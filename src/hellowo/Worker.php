<?php

namespace ryunosuke\hellowo;

use Closure;
use Exception;
use Generator;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
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
    private AbstractDriver    $driver;
    private LoggerInterface   $logger;
    private ListenerInterface $listener;
    private array             $signals;
    private int               $timeout;
    private Closure           $restart;
    private Closure           $work;

    private string    $reserved;
    private Generator $current;

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
        if (!isset($options['driver']) || !$options['driver'] instanceof AbstractDriver) {
            throw new InvalidArgumentException("driver is required");
        }
        if (isset($options['signals'][pcntl::SIGUSR1]) || isset($options['signals'][pcntl::SIGALRM])) {
            throw new InvalidArgumentException("SIGUSR1,SIGALRM is reserved");
        }
        if (isset($options['listener']) && !$options['listener'] instanceof ListenerInterface) {
            throw new InvalidArgumentException("listener must be Listener");
        }

        $this->driver   = $options['driver'];
        $this->logger   = $options['logger'] ?? new EchoLogger(LogLevel::DEBUG);
        $this->listener = $options['listener'] ?? new NullListener();
        $this->signals  = ($options['signals'] ?? []) + self::HANDLING_SIGNALS;
        $this->timeout  = $options['timeout'] ?? 0;
        $this->restart  = $this->restartClosure($options['restart'] ?? null);

        if (isset($options['work'])) {
            $this->work = $options['work'];
        }

        register_shutdown_function(fn() => $this->shutdown());
    }

    /**
     * start as worker
     */
    public function start(?callable $work = null): void
    {
        $this->work ??= Closure::fromCallable($work);
        assert($this->work instanceof Closure);

        $running = true;
        $mypid   = getmypid();

        // setup
        $this->logger->info("[$mypid]start: {$this->logString($this->driver)}");
        try {
            $this->driver->setup();
            $this->driver->daemonize();
        }
        catch (Exception $e) {
            $this->logger->warning("[$mypid]setup: {$this->logString($e)}");
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
        $start           = microtime(true);
        $cycle           = 0;
        $continuity      = 0;
        $continuityLevel = 4;
        $stoodby         = $this->driver->isStandby();
        $this->logger->info("[$mypid]begin: {$this->logString($cycle)}");
        while ($running) {
            try {
                $exitcode = ($this->restart)($start, $cycle);
                if ($exitcode !== null) {
                    throw new ExitException("code $exitcode", $exitcode);
                }

                // check standby(e.g. filesystem:unmount, mysql:replication, etc)
                if ($stoodby && $this->driver->isStandby()) {
                    $this->logger->info("[$mypid]sleep: {$this->logString($cycle)}");
                    usleep(10 * 1000 * 1000);
                    continue;
                }

                // when standby is released, restart for initialization
                if ($stoodby) {
                    $this->listener->onStandup($this->driver);
                    throw new ExitException("standby is released", 1);
                }

                // select next job and run
                $this->current = $generator = $this->driver->select();
                try {
                    $message = $generator->current();
                    if ($message === null) {
                        $continuity = 0;
                        $this->logger->debug("[$mypid]breather: {$this->logString($cycle)}");
                        $this->listener->onBreather($cycle);
                    }
                    else {
                        $continuity++;
                        $this->logger->info("[$mypid]job: {$this->logString($message->getId())}");

                        try {
                            $microtime = microtime(true);
                            pcntl::alarm($this->timeout);
                            $this->reserved = str_repeat('x', 2 * 1024 * 1024);
                            try {
                                $return = ($this->work)($message);
                            }
                            finally {
                                pcntl::alarm(0);
                                unset($this->reserved);
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
                        finally {
                            $this->logger->info("[$mypid]finish: {$this->logString(microtime(true) - $microtime)} seconds");
                            $this->listener->onFinish($message);
                        }
                    }
                }
                catch (Throwable $t) {
                    throw $generator->throw($t) ?? $t;
                }
                finally {
                    unset($generator);
                }

                if ($continuity >= 2 ** $continuityLevel) {
                    $continuityLevel = min(8, ++$continuityLevel); // stop 256 limit
                    $this->logger->warning("[$mypid]continue: {$this->logString($continuity)}");
                }

                $this->logger->debug("[$mypid]cycle: {$this->logString($cycle)}, continuity: {$this->logString($continuity)}");
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
                    throw $e;
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

    private function restartClosure($restartMode): Closure
    {
        // as it is
        if (is_callable($restartMode)) {
            return Closure::fromCallable($restartMode);
        }
        // lifetime
        if (is_int($restartMode) || is_float($restartMode)) {
            return fn($start, $cycle) => (microtime(true) - $start) > $restartMode ? 1 : null;
        }
        // included file was modified
        if ($restartMode == 'change') {
            return function ($start, $cycle) {
                foreach (get_included_files() as $filename) {
                    $mtime = file_exists($filename) ? filemtime($filename) : PHP_INT_MAX;
                    if ($mtime > $start) {
                        return 1;
                    }
                }
                return null;
            };
        }

        // default
        return fn() => null;
    }

    private function shutdown(): void
    {
        $error = error_get_last();
        if ($error && stripos($error['message'], 'Allowed memory size') !== false) {
            unset($this->reserved);
            gc_collect_cycles();

            if (isset($this->current) && $this->current->valid()) {
                $message = $this->current->current();
                $this->current->send(null);

                $mypid  = getmypid();
                $job_id = ($message instanceof Message ? $message->getId() : '');
                $this->logger->alert("[$mypid]abort: $job_id");
            }
        }
    }
}
