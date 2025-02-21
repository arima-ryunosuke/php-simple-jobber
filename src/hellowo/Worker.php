<?php

namespace ryunosuke\hellowo;

use Closure;
use Exception;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use ryunosuke\hellowo\Driver\AbstractDriver;
use ryunosuke\hellowo\Exception\ExitException;
use ryunosuke\hellowo\Exception\RetryableException;
use ryunosuke\hellowo\Exception\TimeoutException;
use ryunosuke\hellowo\ext\pcntl;
use ryunosuke\hellowo\Listener\ListenerInterface;
use ryunosuke\hellowo\Listener\NullListener;
use ryunosuke\hellowo\Logger\EchoLogger;
use Throwable;

class Worker extends API
{
    private Closure           $work;
    private AbstractDriver    $driver;
    private LoggerInterface   $logger;
    private ListenerInterface $listener;
    private array             $signals;
    private int               $timeout;
    private Closure           $restart;

    /**
     * constructor
     *
     * @param array $options
     *   - work(callable): executable task
     *   - driver(AbstractDriver): queue driver
     *   - logger(LoggerInterface): psr logger
     *   - signals(?callable[]): handling signals. if value is null then default handler
     *   - listener(Listener): event emitter. events are 'done', 'retry', 'timeout', 'fail'
     *   - timeout(int): work timeout second
     *   - restart(mixed): restart condition. "change" is deprecated because it is for debugging
     */
    public function __construct(array $options = [])
    {
        if (!isset($options['work']) || !is_callable($options['work'])) {
            throw new InvalidArgumentException("work is required");
        }
        if (!isset($options['driver']) || !$options['driver'] instanceof AbstractDriver) {
            throw new InvalidArgumentException("driver is required");
        }
        if (isset($options['signals'][pcntl::SIGUSR1]) || isset($options['signals'][pcntl::SIGALRM])) {
            throw new InvalidArgumentException("SIGUSR1,SIGALRM is reserved");
        }
        if (isset($options['listener']) && !$options['listener'] instanceof ListenerInterface) {
            throw new InvalidArgumentException("listener must be Listener");
        }

        $this->work     = Closure::fromCallable($options['work']);
        $this->driver   = $options['driver'];
        $this->logger   = $options['logger'] ?? new EchoLogger();
        $this->listener = $options['listener'] ?? new NullListener();
        $this->signals  = ($options['signals'] ?? []) + self::HANDLING_SIGNALS;
        $this->timeout  = $options['timeout'] ?? 0;
        $this->restart  = $this->restartClosure($options['restart'] ?? null);
    }

    /**
     * start as worker
     */
    public function start(): void
    {
        $running = true;
        $mypid   = getmypid();

        // setup
        $this->logger->info("[$mypid]start: {$this->logString($this->driver)}");
        if (!$this->driver->isStandby()) {
            $this->driver->setup();
            $this->driver->daemonize();
        }

        // signal handling
        pcntl::async_signals(true);
        pcntl::signal(pcntl::SIGUSR1, fn() => $this->logger->debug("[$mypid]signal: {$this->logString(pcntl::SIGUSR1)}({$this->logString(pcntl::strsignal(pcntl::SIGUSR1))})"));
        pcntl::signal(pcntl::SIGALRM, fn() => TimeoutException::throw());
        foreach ($this->signals as $signal => $handler) {
            pcntl::signal($signal, $handler ?? function ($signo) use (&$running, $mypid) {
                $running = false;
                $this->logger->notice("[$mypid]stop: {$this->logString($signo)}({$this->logString(pcntl::strsignal($signo))})");
            });
        }

        // main loop
        $start   = microtime(true);
        $cycle   = 0;
        $stoodby = false;
        $this->logger->info("[$mypid]begin: {$this->logString($cycle)}");
        while ($running) {
            try {
                $exitcode = ($this->restart)($start, $cycle);
                if ($exitcode !== null) {
                    throw new ExitException("code $exitcode", $exitcode);
                }

                // check standby(e.g. filesystem:unmount, mysql:replication, etc)
                if ($this->driver->isStandby()) {
                    $stoodby = true;
                    $this->logger->info("[$mypid]sleep: {$this->logString($cycle)}");
                    usleep(10 * 1000 * 1000);
                    continue;
                }

                // when standby is released, restart for initialization
                if ($stoodby) {
                    throw new ExitException("standby is released", 1);
                }

                // select next job and run
                $generator = $this->driver->select();
                try {
                    $message = $generator->current();
                    if ($message !== null) {
                        $this->logger->info("[$mypid]job: {$this->logString($message->getId())}");

                        try {
                            $microtime = microtime(true);
                            pcntl::alarm($this->timeout);
                            try {
                                $return = ($this->work)($message);
                            }
                            finally {
                                pcntl::alarm(0);
                            }
                            $this->logger->info("[$mypid]done: {$this->logString($return)}");
                            $generator->send(null);
                            $this->listener->onDone($message, $return);
                        }
                        catch (RetryableException $e) {
                            $this->logger->notice("[$mypid]retry: {$this->logString("after {$e->getSecond()} seconds")}");
                            $generator->send($e->getSecond());
                            $this->listener->onRetry($message, $e);
                        }
                        catch (TimeoutException $e) {
                            $this->logger->warning("[$mypid]timeout: {$this->logString("elapsed {$e->getElapsed($microtime)} seconds")}");
                            $generator->send(null);
                            $this->listener->onTimeout($message, $e);
                        }
                        catch (Exception $e) {
                            $this->logger->error("[$mypid]fail: {$this->logString($e)}");
                            $generator->send(null);
                            $this->listener->onFail($message, $e);
                        }
                    }
                }
                catch (Throwable $t) {
                    throw $generator->throw($t) ?? $t;
                }
                finally {
                    unset($generator);
                }

                $this->logger->debug("[$mypid]cycle: {$this->logString($cycle)}");
                $this->listener->onCycle($cycle);
                pcntl::signal_dispatch();
                gc_collect_cycles();
                $cycle++;
            }
            catch (ExitException $e) {
                $this->logger->notice("[$mypid]exit: {$this->logString($e)}");
                $e->exit();
            }
            catch (Exception $e) {
                $this->logger->error("[$mypid]exception: {$this->logString($e)}");
                if ($this->driver->error($e)) {
                    break;
                }
                usleep(0.1 * 1000 * 1000);
            }
            catch (Throwable $t) {
                $this->logger->critical("[$mypid]error: {$this->logString($t)}");
                throw $t;
            }
        }

        $this->driver->close();

        $this->logger->info("[$mypid]end: {$this->logString($cycle)}");
    }

    private function logString($log_data): string
    {
        $stringify = function (&$v) {
            if ($v instanceof Throwable) {
                $v = sprintf('caught %s(%s, %s) in %s:%s', get_class($v), $v->getCode(), $v->getMessage(), $v->getFile(), $v->getLine());
            }
            if (is_resource($v) || (is_object($v) && method_exists($v, '__toString'))) {
                $v = (string) $v;
            }
        };
        if (is_array($log_data)) {
            array_walk_recursive($log_data, $stringify);
        }
        else {
            $stringify($log_data);
        }
        if ((is_object($log_data) && !method_exists($log_data, '__toString')) || is_array($log_data)) {
            $log_data = json_encode($log_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return (string) $log_data;
    }
}
