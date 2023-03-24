<?php

namespace ryunosuke\hellowo\Exception;

use RuntimeException;

class AbstractException extends RuntimeException
{
    public static function throw(...$args)
    {
        throw new static(...$args);
    }
}
