<?php

namespace ryunosuke\hellowo\Exception;

use RuntimeException;

class ExitException extends RuntimeException
{
    use ExceptionTrait;

    public function exit(): void
    {
        // for unittesting
        if (defined('PHPUNIT_COMPOSER_INSTALL')) {
            throw $this;
        }
        exit($this->getCode()); // @codeCoverageIgnore
    }
}
