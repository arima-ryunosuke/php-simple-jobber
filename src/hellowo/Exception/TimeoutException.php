<?php

namespace ryunosuke\hellowo\Exception;

class TimeoutException extends AbstractException
{
    public function getElapsed(float $start): float
    {
        return microtime(true) - $start;
    }
}
