<?php

namespace ryunosuke\hellowo;

use Closure;
use Exception;
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

    /**
     * constructor
     *
     * @param array $options
     *   - driver(AbstractDriver): queue driver
     *   - logger(LoggerInterface): psr logger
     *   - signals(?callable[]): handling signals. if value is null then default handler
     *   - listener(Listener): event emitter. events are 'done', 'retry', 'timeout', 'fail'
     *   - timeout(int): default work timeout second
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

        eval(<<<'PHP'
        namespace {
            if (!function_exists('register_shutdown_function')) {
                function register_shutdown_function(callable $callback, mixed ...$args)
                {
                    return $GLOBALS['hellowo-shutdown_function'][] = [$callback, $args];
                }
            }
        }
        PHP);
    }

    /**
     * start as worker
     */
    public function start(callable $work): void
    {
        $running = true;
        $mypid   = getmypid();

        // setup
        $this->logger->info("[{mypid}]{event}: {driver}", ['event' => 'start', 'mypid' => $mypid, 'driver' => $this->logString($this->driver)]);
        try {
            $this->driver->setup();
            $this->driver->daemonize();
        }
        catch (Exception $e) {
            $this->logger->warning("[{mypid}]{event}: {exception}", ['event' => 'setup', 'mypid' => $mypid, 'exception' => $this->logString($e)]);
        }

        // signal handling
        pcntl::async_signals(true);
        pcntl::signal(pcntl::SIGUSR1, fn($signo) => $this->logger->debug("[{mypid}]{event}: {signo}({sigstr})", ['event' => 'signal', 'mypid' => $mypid, 'signo' => $signo, 'sigstr' => pcntl::strsignal($signo)]));
        pcntl::signal(pcntl::SIGALRM, fn() => TimeoutException::throw());
        foreach ($this->signals as $signal => $handler) {
            pcntl::signal($signal, $handler ?? function ($signo) use (&$running, $mypid) {
                $running = false;
                $this->logger->notice("[{mypid}]{event}: {signo}({sigstr})", ['event' => 'stop', 'mypid' => $mypid, 'signo' => $signo, 'sigstr' => pcntl::strsignal($signo)]);
            });
        }

        // main loop
        $start           = microtime(true);
        $cycle           = 0;
        $workload        = 0;
        $continuity      = 0;
        $continuities    = [];
        $continuityLevel = 0;
        $stoodby         = $this->driver->isStandby();
        $this->logger->info("[{mypid}]{event}: {cycle}", ['event' => 'begin', 'mypid' => $mypid, 'cycle' => $cycle]);
        while ($running) {
            try {
                $exitcode = ($this->restart)($start, $cycle, $workload);
                if ($exitcode !== null) {
                    throw new ExitException("code $exitcode", $exitcode);
                }

                // check standby(e.g. filesystem:unmount, mysql:replication, etc)
                if ($stoodby && $this->driver->isStandby()) {
                    $this->logger->info("[{mypid}]{event}: {cycle}", ['event' => 'sleep', 'mypid' => $mypid, 'cycle' => $cycle]);
                    usleep(10 * 1000 * 1000);
                    continue;
                }

                // when standby is released, restart for initialization
                if ($stoodby) {
                    $this->listener->onStandup($this->driver);
                    throw new ExitException("standby is released", 1);
                }

                // select next job and run
                $generator = $this->driver->select();
                try {
                    /** @var ?Message $message */
                    $message = $generator->current();
                    if ($message === null) {
                        $continuity--;
                    }
                    else {
                        $workload++;
                        $continuity++;
                        $this->logger->info("[{mypid}]{event}: {job_id}", ['event' => 'job', 'mypid' => $mypid, 'job_id' => $message->getId()]);

                        try {
                            $microtime = microtime(true);
                            pcntl::alarm($message->getTimeout() ?: $this->timeout);
                            try {
                                $return = $work($message);
                            }
                            finally {
                                pcntl::alarm(0);
                            }
                            $this->logger->info("[{mypid}]{event}: {return}", ['event' => 'done', 'mypid' => $mypid, 'return' => $this->logString($return)]);
                            $generator->send(null);
                            $this->listener->onDone($message, $return);
                        }
                        catch (RetryableException $e) {
                            $this->logger->notice("[{mypid}]{event}: {after} seconds", ['event' => 'retry', 'mypid' => $mypid, 'after' => $e->getSecond()]);
                            $generator->send($e->getSecond());
                            $this->listener->onRetry($message, $e);
                        }
                        catch (TimeoutException $e) {
                            $this->logger->warning("[{mypid}]{event}: {elapsed} seconds", ['event' => 'timeout', 'mypid' => $mypid, 'elapsed' => $e->getElapsed($microtime)]);
                            $generator->send($e);
                            $this->listener->onTimeout($message, $e);
                        }
                        catch (Exception $e) {
                            $this->logger->error("[{mypid}]{event}: {exception}", ['event' => 'fail', 'mypid' => $mypid, 'exception' => $this->logString($e)]);
                            $generator->send($e);
                            $this->listener->onFail($message, $e);
                        }
                        finally {
                            $this->logger->info("[{mypid}]{event}: {job_id}({elapsed}) seconds", ['event' => 'finish', 'mypid' => $mypid, 'job_id' => $message->getId(), 'elapsed' => microtime(true) - $microtime]);
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

                $continuity     = max(0, $continuity);
                $continuities[] = $continuity;
                $continuities   = array_slice($continuities, -11);

                $ratio = $continuity - (array_sum($continuities) / count($continuities));
                $level = array_values(array_filter([0, 1, 2, 3, 4, 5], fn($level) => $continuity < (2 ** ($level + 4))))[0] ?? 6;
                if ($ratio > 0 && $level > $continuityLevel) {
                    $continuityLevel = $level;
                    $this->logger->warning("[{mypid}]{event}: {continuity}/{continuityLevel}", ['event' => 'busy', 'mypid' => $mypid, 'continuity' => $continuity, 'continuityLevel' => $continuityLevel]);
                    $this->listener->onBusy($continuity);
                }
                if ($ratio < 0 && $level < $continuityLevel) {
                    $continuityLevel = $level;
                    $this->logger->info("[{mypid}]{event}: {continuity}/{continuityLevel}", ['event' => 'idle', 'mypid' => $mypid, 'continuity' => $continuity, 'continuityLevel' => $continuityLevel]);
                    $this->listener->onIdle($continuity);
                }

                if (isset($GLOBALS['hellowo-shutdown_function'])) {
                    foreach ($GLOBALS['hellowo-shutdown_function'] as [$callback, $args]) {
                        $callback(...$args);
                    }
                    $GLOBALS['hellowo-shutdown_function'] = [];
                }

                $this->logger->debug("[{mypid}]{event}: {workload}/{cycle}, continuity: {continuity}", ['event' => 'cycle', 'mypid' => $mypid, 'cycle' => $cycle, 'workload' => $workload, 'continuity' => $continuity]);
                $this->listener->onCycle($cycle);
                pcntl::signal_dispatch();
                gc_collect_cycles();
                $cycle++;
            }
            catch (ExitException $e) {
                $this->logger->notice("[{mypid}]{event}: {exception}", ['event' => 'exit', 'mypid' => $mypid, 'exception' => $this->logString($e)]);
                $e->exit();
            }
            catch (Exception $e) {
                $this->logger->error("[{mypid}]{event}: {exception}", ['event' => 'exception', 'mypid' => $mypid, 'exception' => $this->logString($e)]);
                if ($this->driver->error($e)) {
                    throw $e;
                }
                usleep(0.1 * 1000 * 1000);
            }
            catch (Throwable $t) {
                $this->logger->critical("[{mypid}]{event}: {exception}", ['event' => 'error', 'mypid' => $mypid, 'exception' => $this->logString($t)]);
                throw $t;
            }
        }

        $this->driver->close();

        $this->logger->info("[{mypid}]{event}: {workload}/{cycle}", ['event' => 'end', 'mypid' => $mypid, 'cycle' => $cycle, 'workload' => $workload]);
    }

    private function restartClosure($restartMode): Closure
    {
        // as it is
        if (is_callable($restartMode)) {
            return Closure::fromCallable($restartMode);
        }
        // lifetime
        if (is_int($restartMode) || is_float($restartMode)) {
            return fn($start, $cycle, $workload) => (microtime(true) - $start) > $restartMode ? 1 : null;
        }
        // included file was modified
        if ($restartMode == 'change') {
            return function ($start, $cycle, $workload) {
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
}
