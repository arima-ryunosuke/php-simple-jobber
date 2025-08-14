<?php

namespace ryunosuke\hellowo\Logger;

use DateTime;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use ReflectionClass;

/**
 * @codeCoverageIgnore this is reference implementation
 */
class EchoLogger extends AbstractLogger
{
    private $level;

    public function __construct($level)
    {
        $this->level = $level;
    }

    public function log($level, $message, array $context = [])
    {
        static $levels = null;
        $levels ??= array_flip(array_values((new ReflectionClass(LogLevel::class))->getConstants()));

        if ($levels[$this->level] < $levels[$level]) {
            return;
        }

        $now = (new DateTime())->format('Y-m-d\\TH:i:s.v');

        echo "[$now][$level] $message\n";
    }
}
