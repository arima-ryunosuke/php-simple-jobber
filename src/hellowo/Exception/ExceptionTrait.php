<?php

namespace ryunosuke\hellowo\Exception;

trait ExceptionTrait
{
    public static function throw(...$args)
    {
        throw new static(...$args);
    }
}
