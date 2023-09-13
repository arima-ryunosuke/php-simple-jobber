<?php

namespace ryunosuke\hellowo;

use Closure;
use Exception;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use ryunosuke\hellowo\Driver\AbstractDriver;
use ryunosuke\hellowo\Exception\RetryableException;
use ryunosuke\hellowo\Exception\TimeoutException;
use ryunosuke\hellowo\ext\pcntl;
use ryunosuke\hellowo\Logger\EchoLogger;
use Throwable;

class Worker extends API
{
    private Closure         $work;
    private AbstractDriver  $driver;
    private LoggerInterface $logger;
    private Listener        $listener;
    private array           $signals;
    private int             $timeout;

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
        if (isset($options['listener']) && !$options['listener'] instanceof Listener) {
            throw new InvalidArgumentException("listener must be Listener");
        }

        $this->work     = Closure::fromCallable($options['work']);
        $this->driver   = $options['driver'];
        $this->logger   = $options['logger'] ?? new EchoLogger();
        $this->listener = $options['listener'] ?? $this->NullListener();
        $this->signals  = ($options['signals'] ?? []) + self::HANDLING_SIGNALS;
        $this->timeout  = $options['timeout'] ?? 0;
    }

    /**
     * start as worker
     */
    public function start(): void
    {
        $running = true;

        // setup
        $mypid = getmypid();
        $this->logger->info("start: {$this->logString($mypid)}");
        $this->driver->setup();
        $this->driver->daemonize();

        // signal handling
        pcntl::async_signals(true);
        pcntl::signal(pcntl::SIGUSR1, fn() => $this->logger->debug('signal: USR1'));
        pcntl::signal(pcntl::SIGALRM, fn() => TimeoutException::throw());
        foreach ($this->signals as $signal => $handler) {
            pcntl::signal($signal, $handler ?? function ($signo) use (&$running) {
                $running = false;
                $this->logger->notice("stop: {$this->logString($signo)}");
            });
        }

        // main loop
        $cycle = 0;
        $this->logger->info("begin: {$this->logString($this->driver)}");
        while ($running) {
            try {
                // select next job and run
                $message = $this->driver->select();
                if ($message !== null) {
                    $this->logger->info("job: {$this->logString($message->getId())}");

                    try {
                        $microtime = microtime(true);
                        pcntl::alarm($this->timeout);
                        try {
                            $return = ($this->work)($message);
                        }
                        finally {
                            pcntl::alarm(0);
                        }
                        $this->logger->info("done: {$this->logString($return)}");
                        $this->driver->done($message);
                        $this->listener->onDone($message, $return);
                    }
                    catch (RetryableException $e) {
                        $this->logger->notice("retry: {$this->logString("after {$e->getSecond()} seconds")}");
                        $this->driver->retry($message, $e->getSecond());
                        $this->listener->onRetry($message, $e);
                    }
                    catch (TimeoutException $e) {
                        $this->logger->warning("timeout: {$this->logString("elapsed {$e->getElapsed($microtime)} seconds")}");
                        $this->driver->done($message);
                        $this->listener->onTimeout($message, $e);
                    }
                    catch (Exception $e) {
                        $this->logger->error("fail: {$this->logString($e)}");
                        $this->driver->done($message);
                        $this->listener->onFail($message, $e);
                    }
                }

                $this->logger->debug("cycle: {$cycle}");
                $this->listener->onCycle($cycle);
                pcntl::signal_dispatch();
                gc_collect_cycles();
                $cycle++;
            }
            catch (Exception $e) {
                $this->logger->error("exception: {$this->logString($e)}");
                if ($this->driver->error($e)) {
                    break;
                }
                usleep(0.1 * 1000 * 1000);
            }
            catch (Throwable $t) {
                $this->logger->critical("error: {$this->logString($t)}");
                throw $t;
            }
        }

        $this->driver->close();

        $this->logger->info("end: {$this->logString($mypid)}");
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
