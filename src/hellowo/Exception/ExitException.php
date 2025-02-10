<?php

namespace ryunosuke\hellowo\Exception;

class ExitException extends AbstractException
{
    public function exit(): void
    {
        // for unittesting
        if (defined('PHPUNIT_COMPOSER_INSTALL')) {
            throw $this;
        }
        exit($this->getCode()); // @codeCoverageIgnore
    }
}
