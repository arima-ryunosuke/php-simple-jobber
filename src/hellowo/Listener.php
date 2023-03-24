<?php

namespace ryunosuke\hellowo;

use Throwable;

interface Listener
{
    public function onSend(?string $jobId): void;

    public function onDone(Message $message, $return): void;

    public function onFail(Message $message, Throwable $t): void;

    public function onRetry(Message $message, Throwable $t): void;

    public function onTimeout(Message $message, Throwable $t): void;
}
