<?php

namespace ryunosuke\hellowo\Listener;

use ryunosuke\hellowo\Message;
use Throwable;

class NullListener extends AbstractListener
{
    public function onSend(?string $jobId): void { }

    public function onDone(Message $message, $return): void { }

    public function onFail(Message $message, Throwable $t): void { }

    public function onRetry(Message $message, Throwable $t): void { }

    public function onTimeout(Message $message, Throwable $t): void { }

    public function onCycle(int $cycle): void { }
}
