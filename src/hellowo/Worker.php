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
use ryunosuke\hellowo\ext\posix;
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

    private string    $reserved;
    private Generator $current;

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

        $this->name     = $options['name'] ?? 'hellowo';
        $this->driver   = $options['driver'];
        $this->logger   = $options['logger'] ?? new EchoLogger(LogLevel::DEBUG);
        $this->listener = $options['listener'] ?? new NullListener();
        $this->signals  = ($options['signals'] ?? []) + self::HANDLING_SIGNALS;
        $this->timeout  = $options['timeout'] ?? 0;
        $this->restart  = $this->restartClosure($options['restart'] ?? null);

        if (function_exists('register_shutdown_function')) {
            register_shutdown_function(fn() => $this->shutdown());
        }
        // for compatible
        else {
            // @codeCoverageIgnoreStart
            eval(<<<'PHP'
            namespace {
                function register_shutdown_function(callable $callback, mixed ...$args)
                {
                    return $GLOBALS['hellowo-shutdown_function'][] = [$callback, $args];
                }
            }
            PHP);
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * start as work
     *
     * @deprecated use work()
     * @codeCoverageIgnore
     */
    public function start(callable $work): void
    {
        $this->work($work);
    }

    /**
     * start as work
     */
    public function work(callable $work): void
    {
        foreach ($this->generateWork($work, []) as $ignored) {
            // noop
        }
    }

    /**
     * start as fork
     *
     * @codeCoverageIgnore
     */
    public function fork(callable $work, int $leastCount, ?int $mostCount = null): void
    {
        $ipcSockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($ipcSockets[0], false);
        stream_set_blocking($ipcSockets[1], false);

        foreach ($this->generateFork($work, $leastCount, $mostCount, $ipcSockets) as $ignored) {
            // noop
        }
    }

    private function generateWork(callable $work, array $ipcScokets): Generator
    {
        $running = true;
        $mypid   = getmypid();

        // initialize
        $cmdline = posix::proc_cmdline();
        posix::proc_cmdline("{$cmdline}#{$this->name}");
        $this->driver->name = $this->name;

        // setup
        $this->logger->info("[{mypid}]{event}: {driver}", ['event' => 'start', 'mypid' => $mypid, 'driver' => $this->logString($this->driver)]);
        try {
            $this->driver->setup();
            $this->driver->daemonize();
        }
        catch (Exception $e) {
            $this->logger->warning("[{mypid}]{event}: {exception}", ['event' => 'setup', 'mypid' => $mypid, 'exception' => $e]);
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
        $laptime         = 0;
        $cycle           = 0;
        $workload        = 0;
        $continuity      = 0;
        $continuities    = [];
        $continuityLevel = 0;
        $stoodby         = $this->driver->isStandby();
        $this->logger->info("[{mypid}]{event}: {cycle}", ['event' => 'begin', 'mypid' => $mypid, 'cycle' => $cycle]);
        while ($running) {
            yield $cycle;

            $now = microtime(true);

            try {
                // update status(per 1 second)
                if (($now - $laptime) >= 1) {
                    $laptime = $now;
                    $stats   = sprintf('Time:%ss Memory:%sMB Work:%s Cycle:%s',
                        (int) ($now - $start),
                        number_format(memory_get_usage(true) / 1000 / 1000, 2, '.', ''),
                        $workload,
                        $cycle,
                    );
                    posix::proc_cmdline("{$cmdline}#{$this->name}($stats)");
                    $this->logger->debug("[{mypid}]{event}: {memory}", ['event' => 'status', 'mypid' => $mypid, 'memory' => memory_get_usage(true)]);
                }

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
                $this->current = $generator = $this->driver->select();
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
                            $this->reserved = str_repeat('x', 2 * 1024 * 1024);
                            try {
                                $return = $work($message);
                            }
                            finally {
                                pcntl::alarm(0);
                                unset($this->reserved);
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
                            $this->logger->error("[{mypid}]{event}: {exception}", ['event' => 'fail', 'mypid' => $mypid, 'exception' => $e]);
                            $generator->send($e);
                            $this->listener->onFail($message, $e);
                        }
                        finally {
                            $this->logger->info("[{mypid}]{event}: {job_id}({elapsed} seconds)", ['event' => 'finish', 'mypid' => $mypid, 'job_id' => $message->getId(), 'elapsed' => microtime(true) - $microtime]);
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
                    if ($ipcScokets) {
                        fwrite($ipcScokets[1], "increase\n");
                    }
                }
                if ($ratio < 0 && $level < $continuityLevel) {
                    $continuityLevel = $level;
                    $this->logger->info("[{mypid}]{event}: {continuity}/{continuityLevel}", ['event' => 'idle', 'mypid' => $mypid, 'continuity' => $continuity, 'continuityLevel' => $continuityLevel]);
                    $this->listener->onIdle($continuity);
                    if ($ipcScokets) {
                        fwrite($ipcScokets[1], "decrease\n");
                    }
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
                $this->logger->notice("[{mypid}]{event}: {exception}", ['event' => 'exit', 'mypid' => $mypid, 'exception' => $e]);
                $e->exit();
            }
            catch (Exception $e) {
                usleep(1 * 1000 * 1000);
                $this->logger->error("[{mypid}]{event}: {exception}", ['event' => 'exception', 'mypid' => $mypid, 'exception' => $e]);
                if ($this->driver->error($e)) {
                    throw $e;
                }
            }
            catch (Throwable $t) {
                usleep(1 * 1000 * 1000);
                $this->logger->critical("[{mypid}]{event}: {exception}", ['event' => 'error', 'mypid' => $mypid, 'exception' => $t]);
                throw $t;
            }
        }

        $this->driver->close();

        $this->logger->info("[{mypid}]{event}: {workload}/{cycle}", ['event' => 'end', 'mypid' => $mypid, 'cycle' => $cycle, 'workload' => $workload]);
    }

    private function generateFork(callable $work, int $leastCount, ?int $mostCount, array $ipcSockets): Generator
    {
        assert($mostCount === null || $mostCount >= $leastCount);

        $exited   = false;
        $reaped   = false;
        $reloaded = false;
        $pids     = [];
        $mypid    = getmypid();

        // shortcut for fork
        $fork = function (array $pdata) use ($work, &$pids, $mypid, $ipcSockets): ?int {
            // protect signal queue from CoW
            pcntl::sigprocmask(pcntl::SIG_BLOCK, [pcntl::SIGHUP, pcntl::SIGCHLD]);
            pcntl::signal_dispatch();

            $pid = pcntl::fork();

            // unprotect signal queue from CoW
            pcntl::sigprocmask(pcntl::SIG_UNBLOCK, [pcntl::SIGHUP, pcntl::SIGCHLD]);

            if ($pid > 0) {
                $this->logger->info("[{mypid}][master]{event}: {pid}({count} count)", ['event' => 'fork', 'mypid' => $mypid, 'pid' => $pid, 'count' => count($pids) + 1]);
                $pids[$pid] = ['time' => microtime(true)] + $pdata;
            }
            elseif ($pid === 0) {
                // @codeCoverageIgnoreStart
                pcntl::signal(pcntl::SIGCHLD, pcntl::SIG_DFL);
                pcntl::signal(pcntl::SIGHUP, pcntl::SIG_DFL);
                foreach ($this->generateWork($work, $ipcSockets) as $ignored) {
                    // noop
                }
                exit;
                // @codeCoverageIgnoreEnd
            }
            else {
                $errno  = pcntl::errno();
                $errstr = pcntl::strerror($errno);
                $this->logger->error("[{mypid}][master]{event}: {errstr}({errno})", ['event' => 'fork', 'mypid' => $mypid, 'errno' => $errno, 'errstr' => $errstr]);
                return null;
            }
            return $pid;
        };

        // shortcut for kill
        $kill = function (int $pid) use (&$pids, $mypid): bool {
            $killed = posix::kill($pid, pcntl::SIGTERM);
            if ($killed) {
                $this->logger->info("[{mypid}][master]{event}: {pid}({count} count)", ['event' => 'kill', 'mypid' => $mypid, 'pid' => $pid, 'count' => count($pids) - 1]);
                unset($pids[$pid]);
            }
            else {
                $errno  = posix::errno();
                $errstr = posix::strerror($errno);
                $this->logger->warning("[{mypid}][master]{event}: {pid}({errstr}({errno})", ['event' => 'kill', 'mypid' => $mypid, 'pid' => $pid, 'errno' => $errno, 'errstr' => $errstr]);
            }
            return $killed;
        };

        // signal handling
        pcntl::async_signals(true);
        pcntl::signal(pcntl::SIGCHLD, function ($signo) use (&$reaped, $mypid) {
            $reaped = true;
            $this->logger->info("[{mypid}][master]{event}: {signo}({sigstr})", ['event' => 'reap', 'mypid' => $mypid, 'signo' => $signo, 'sigstr' => pcntl::strsignal($signo)]);
        });
        pcntl::signal(pcntl::SIGHUP, function ($signo) use (&$reloaded, $mypid) {
            $reloaded = true;
            $this->logger->info("[{mypid}][master]{event}: {signo}({sigstr})", ['event' => 'reload', 'mypid' => $mypid, 'signo' => $signo, 'sigstr' => pcntl::strsignal($signo)]);
        });
        foreach ($this->signals as $signal => $handler) {
            pcntl::signal($signal, $handler ?? function ($signo) use (&$exited, $mypid) {
                $exited = true;
                $this->logger->notice("[{mypid}][master]{event}: {signo}({sigstr})", ['event' => 'stop', 'mypid' => $mypid, 'signo' => $signo, 'sigstr' => pcntl::strsignal($signo)]);
            });
        }

        $start = microtime(true);
        $this->logger->info("[{mypid}][master]{event}", ['event' => 'begin', 'mypid' => $mypid]);
        try {
            // main loop
            while (!$exited) {
                $exitcode = ($this->restart)($start, -1, -1);
                if ($exitcode !== null) {
                    throw new ExitException("code $exitcode", $exitcode);
                }

                $waitTime        = (yield $pids) ?? 10;
                $waitSecond      = (int) $waitTime;
                $waitMicrosecond = (int) (($waitTime - $waitSecond) * 1000 * 1000);

                $this->logger->debug("[{mypid}][master]{event}: {seconds} seconds", ['event' => 'ticks', 'mypid' => $mypid, 'seconds' => microtime(true) - $start]);
                pcntl::signal_dispatch();
                gc_collect_cycles();

                // least fork
                $least = $leastCount - count($pids);
                if ($least > 0) {
                    for ($i = 0; $i < $least; $i++) {
                        $fork(['type' => 'initial']);
                    }
                }

                // child message
                $read   = [$ipcSockets[0]];
                $write  = [];
                $except = [];
                if (@stream_select($read, $write, $except, $waitSecond, $waitMicrosecond) === false) {
                    usleep(10 * 1000); // @codeCoverageIgnore
                }
                else {
                    foreach ($read as $socket) {
                        while (strlen($childMessage = trim(fgets($socket)))) {
                            $this->logger->info("[{mypid}][master]{event}: {cmessage}", ['event' => 'message', 'mypid' => $mypid, 'cmessage' => $childMessage]);

                            switch ($childMessage) {
                                default:
                                    $this->logger->warning('[{mypid}][master]{event}: unknown message "{cmessage}"', ['event' => 'message', 'mypid' => $mypid, 'cmessage' => $childMessage]);
                                    break;
                                case 'increase':
                                    if (count($pids) < $mostCount) {
                                        $fork(['type' => 'increase']);
                                    }
                                    else {
                                        $this->logger->warning('[{mypid}][master]{event}: you may need to increase $mostCount', ['event' => 'busy', 'mypid' => $mypid]);
                                    }
                                    break;
                                case 'decrease':
                                    foreach ($pids as $pid => $pdata) {
                                        if ($pdata['type'] === 'increase') {
                                            if ($kill($pid)) {
                                                break;
                                            }
                                        }
                                    }
                                    break;
                            }
                        }
                    }
                }

                // wait (no WNOHANG, cannot receive the signal)
                if ($reaped) {
                    $reaped = false;
                    while (($pid = pcntl::wait($status, pcntl::WNOHANG)) > 0) {
                        $wstatus = pcntl::wstatus($status);
                        $this->logger->info("[{mypid}][master]{event} {pid}({status})", ['event' => 'wait', 'mypid' => $mypid, 'pid' => $pid, 'status' => $this->logString($wstatus)]);
                        unset($pids[$pid]);
                    }
                }

                // reload
                if ($reloaded) {
                    $reloaded = false;
                    if (count($pids) > $leastCount) {
                        $this->logger->notice("[{mypid}][master]{event}: failed at increased process", ['event' => 'respawn', 'mypid' => $mypid]);
                    }
                    foreach ($pids as $pid => $pdata) {
                        $kill($pid);
                    }
                }
            }
        }
        catch (ExitException $e) {
            $this->logger->notice("[{mypid}][master]{event}: {exception}", ['event' => 'exit', 'mypid' => $mypid, 'exception' => $e]);
            $e->exit();
        }
        finally {
            foreach ($pids as $pid => $pdata) {
                $kill($pid);
            }
            $this->logger->info("[{mypid}][master]{event}", ['event' => 'end', 'mypid' => $mypid]);
        }

        return $pids;
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
                $this->logger->alert("[{mypid}]{event}: {job_id}", ['event' => 'abort', 'mypid' => $mypid, 'job_id' => $job_id]);
            }
        }
    }
}
