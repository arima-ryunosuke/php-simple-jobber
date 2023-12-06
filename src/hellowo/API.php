<?php

namespace ryunosuke\hellowo;

use Exception;
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
     * @return ?Message
     */
    protected function select(): ?Message { }

    /**
     * called when complete
     *
     * @param Message $message message of select
     * @return void
     */
    protected function done(Message $message): void { }

    /**
     * called when retry
     *
     * @param Message $message message of select
     * @param float $time next retry seconds
     * @return void
     */
    protected function retry(Message $message, float $time): void { }

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
}
