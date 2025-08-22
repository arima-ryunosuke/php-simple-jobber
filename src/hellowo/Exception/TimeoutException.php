<?php

namespace ryunosuke\hellowo\Exception;

use RuntimeException;

class TimeoutException extends RuntimeException
{
    use ExceptionTrait;

    public function getElapsed(float $start): float
    {
        return microtime(true) - $start;
    }
}
