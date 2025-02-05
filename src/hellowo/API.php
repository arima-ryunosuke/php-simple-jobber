<?php

namespace ryunosuke\hellowo;

use Closure;
use Exception;
use Generator;
use ryunosuke\hellowo\ext\pcntl;
use ryunosuke\hellowo\ext\posix;
use Throwable;

// @codeCoverageIgnoreStart
// This constant is only for property assignment dynamically(expression) and has no other meaning
define(__NAMESPACE__ . '\\processDirectory', sys_get_temp_dir() . '/hellowo/proc');
// @codeCoverageIgnoreEnd

/**
 * API spec class
 *
 * declare common protected method both client/worker.
 * public methods can be restricted by inheriting and making them public.
 */
abstract class API
{
    protected const HANDLING_SIGNALS = [
        pcntl::SIGINT  => null,
        pcntl::SIGTERM => null,
    ];

    /** @var string interprocess directory */
    public static string $processDirectory = processDirectory;

    /**
     * @param int $count
     * @return array notified pid
     */
    public static function notifyLocal(int $count = 1): array
    {
        $processes = array_keys(posix::pgrep('#hellowo'));
        shuffle($processes);

        $result = [];
        foreach ($processes as $pid) {
            $killed = false;

            if ($pid !== getmypid()) {
                $killed = posix::kill($pid, pcntl::SIGUSR1);
            }

            if ($killed) {
                $result[] = $pid;
            }
            if (count($result) >= $count) {
                break;
            }
        }

        return $result;
    }

    /**
     * setup schema etc
     *
     * @return void
     */
    protected function setup(bool $forcibly = false): void { }

    /**
     * register daemon property
     *
     * @return void
     */
    protected function daemonize(): void { }

    /**
     * check writable storage/driver
     *
     * @return bool
     */
    protected function isStandby(): bool { return false; }

    /**
     * notify sent event other process
     *
     * @return int
     */
    protected function notify(int $count = 1): int { return 0; }

    /**
     * select next message
     *
     * @return Generator<Message>
     */
    protected function select(): Generator { }

    /**
     * called when error
     *
     * if returns true, break main loop of worker.
     * you may want to ping or reconnect here.
     *
     * @param Exception $e thrown object
     * @return bool server dead
     */
    protected function error(Exception $e): bool { }

    /**
     * close resource on end main loop of worker
     *
     * @return void
     */
    protected function close(): void { }

    /**
     * send message
     *
     * @param string $contents message body
     * @param ?int $priority the higher the value, the higher the priority
     * @param ?float $delay delay seconds
     * @return ?string job id if supported
     */
    protected function send(string $contents, ?int $priority = null, ?float $delay = null): ?string { }

    /**
     * clear message (for debug/testing)
     *
     * must not call this on production.
     *
     * @return int clear count
     */
    protected function clear(): int { }

    /**
     * new NullListener for doNothing
     *
     * @return Listener
     */
    protected function NullListener(): Listener
    {
        return new class implements Listener {
            public function onSend(?string $jobId): void { }

            public function onDone(Message $message, $return): void { }

            public function onFail(Message $message, Throwable $t): void { }

            public function onRetry(Message $message, Throwable $t): void { }

            public function onTimeout(Message $message, Throwable $t): void { }

            public function onCycle(int $cycle): void { }
        };
    }

    /**
     * new Closure for restart
     *
     * @return Closure
     */
    protected function restartClosure($restartMode): Closure
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
}
