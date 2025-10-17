<?php

namespace ryunosuke\hellowo;

use DateTimeInterface;
use Exception;
use Generator;
use JsonSerializable;
use ryunosuke\hellowo\ext\pcntl;
use ryunosuke\hellowo\ext\posix;
use stdClass;

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

    protected string $name = "";

    /**
     * @param int $count
     * @return array notified pid
     */
    public function notifyLocal(int $count = 1): array
    {
        $processes = array_keys(posix::pgrep('#{$this->name}'));
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
     * @param mixed $contents message body
     * @param ?int $priority the higher the value, the higher the priority
     * @param null|float|string|DateTimeInterface $time delay expression
     * @param int $timeout job timeout
     * @return ?string job id if supported
     */
    protected function send(string $contents, ?int $priority = null, /*null|float|string|DateTimeInterface*/ $time = null, int $timeout = 0): ?string { }

    /**
     * cancel message
     *
     * @param ?string $job_id job id
     * @param ?string $contents message body
     * @return int cancel count
     */
    protected function cancel(?string $job_id = null, ?string $contents = null): int { }

    /**
     * clear message (for debug/testing)
     *
     * must not call this on production.
     *
     * @return int clear count
     */
    protected function clear(): int { }

    /**
     * stringify for message
     *
     * @return string message string
     */
    protected function messageString($contents): string
    {
        if ($contents instanceof JsonSerializable || is_array($contents) || (is_object($contents) && get_class($contents) === 'stdClass')) {
            return json_encode($contents, JSON_UNESCAPED_UNICODE);
        }
        return $contents;
    }

    /**
     * stringify for log
     *
     * @return string log string
     */
    protected function logString($log_data): string
    {
        if (is_bool($log_data) || is_null($log_data)) {
            return json_encode($log_data);
        }

        $is_array = is_array($log_data);
        if (!$is_array) {
            $log_data = [$log_data];
        }

        array_walk_recursive($log_data, function (&$v) {
            if (is_resource($v) || (is_object($v) && method_exists($v, '__toString'))) {
                $v = (string) $v;
            }
            if ($v instanceof JsonSerializable || (is_object($v) && get_class($v) === stdClass::class)) {
                $v = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            if (is_object($v)) {
                $v = get_class($v) . json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);
            }
        });

        if ($is_array) {
            $log_data = json_encode($log_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        else {
            $log_data = $log_data[0];
        }

        return (string) $log_data;
    }
}
