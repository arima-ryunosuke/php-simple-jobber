<?php

use Psr\Log\AbstractLogger;
use ryunosuke\hellowo\Driver\AbstractDriver;
use ryunosuke\hellowo\Listener\AbstractListener;
use ryunosuke\hellowo\Message;
use ryunosuke\PHPUnit\Actual;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../vendor/ryunosuke/phpunit-extension/inc/bootstrap.php';

\ryunosuke\PHPUnit\Actual::generateStub(__DIR__ . '/../src', __DIR__ . '/.stub', 2);

/**
 * @template T
 * @param T $value
 * @return T|\ryunosuke\PHPUnit\Actual
 */
function that($value)
{
    return new \ryunosuke\PHPUnit\Actual($value);
}

Actual::$functionNamespaces                = [];
Actual::$configuration['errorAsException'] = true;

if (false) {
    /** @noinspection PhpUnreachableStatementInspection */
    define('FILESYSTEM_URL', '');
    define('BEANSTALK_URL', '');
    define('GEARMAN_URL', '');
    define('MYSQL_URL', '');
    define('PGSQL_URL', '');
    define('RABBITMQ_URL', '');
}

class ArrayLogger extends AbstractLogger
{
    public $logs = [];

    public function __construct(&$logs)
    {
        $this->logs = &$logs;
    }

    public function log($level, $message, array $context = [])
    {
        $this->logs[] = $message;
    }
}

class ArrayListener extends AbstractListener
{
    private $events;

    public function __construct(&$events)
    {
        $this->events = &$events;
    }

    public function onSend(?string $jobId): void
    {
        $this->events['send'][] = $jobId;
    }

    public function onDone(Message $message, $return): void
    {
        $this->events['done'][] = $message->getId();
    }

    public function onFail(Message $message, Throwable $t): void
    {
        $this->events['fail'][] = $message->getId();
    }

    public function onRetry(Message $message, Throwable $t): void
    {
        $this->events['retry'][] = $message->getId();
    }

    public function onTimeout(Message $message, Throwable $t): void
    {
        $this->events['timeout'][] = $message->getId();
    }

    public function onFinish(Message $message): void
    {
        $this->events['finish'][] = $message->getId();
    }

    public function onBreather(int $cycle): void
    {
        $this->events['breather'][] = $cycle;
    }

    public function onStandup(AbstractDriver $driver): void
    {
        $this->events['standup'][] = $driver;
    }

    public function onCycle(int $cycle): void
    {
        $this->events['cycle'][] = $cycle;
    }
}
