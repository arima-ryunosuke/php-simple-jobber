<?php

namespace ryunosuke\hellowo\Logger;

use DateTime;
use Psr\Log\AbstractLogger;

/**
 * @codeCoverageIgnore this is reference implementation
 */
class EchoLogger extends AbstractLogger
{
    public function log($level, $message, array $context = [])
    {
        $now = (new DateTime())->format('Y-m-d\\TH:i:s.v');

        echo "[$now][$level] $message\n";
    }
}
