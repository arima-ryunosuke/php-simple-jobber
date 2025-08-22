<?php

namespace ryunosuke\hellowo\Exception;

use RuntimeException;
use Throwable;

class RetryableException extends RuntimeException
{
    use ExceptionTrait;

    private float $second;

    public function __construct(float $second, string $message = "", int $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);

        $this->second = $second;
    }

    public function getSecond(): float
    {
        return $this->second;
    }
}
