<?php

namespace ryunosuke\hellowo\Listener;

use ryunosuke\hellowo\Driver\AbstractDriver;
use ryunosuke\hellowo\Message;
use Throwable;

interface ListenerInterface
{
    public function onSend(?string $jobId): void;

    public function onDone(Message $message, $return): void;

    public function onFail(Message $message, Throwable $t): void;

    public function onRetry(Message $message, Throwable $t): void;

    public function onTimeout(Message $message, Throwable $t): void;

    public function onFinish(Message $message): void;

    public function onBusy(int $continuity): void;

    public function onIdle(int $continuity): void;

    public function onStandup(AbstractDriver $driver): void;

    public function onCycle(int $cycle): void;
}
