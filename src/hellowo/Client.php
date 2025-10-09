<?php

namespace ryunosuke\hellowo;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ryunosuke\hellowo\Driver\AbstractDriver;
use ryunosuke\hellowo\Listener\ListenerInterface;
use ryunosuke\hellowo\Listener\NullListener;

class Client extends API
{
    private AbstractDriver    $driver;
    private LoggerInterface   $logger;
    private ListenerInterface $listener;

    /**
     * constructor
     *
     * @param array $options
     *   - driver(AbstractDriver): queue driver
     *   - logger(LoggerInterface): psr logger
     *   - listener(Listener): event emitter. events are 'send'
     */
    public function __construct(array $options = [])
    {
        if (!isset($options['driver']) || !$options['driver'] instanceof AbstractDriver) {
            throw new InvalidArgumentException("driver is required");
        }
        if (isset($options['listener']) && !$options['listener'] instanceof ListenerInterface) {
            throw new InvalidArgumentException("listener must be Listener");
        }

        $this->driver   = $options['driver'];
        $this->logger   = $options['logger'] ?? new NullLogger();
        $this->listener = $options['listener'] ?? new NullListener();
    }

    public function setup(bool $forcibly = false): void
    {
        $this->logger->info("{event}: {vars}", ['event' => 'setup', 'vars' => $this->logString(get_defined_vars())]);
        $this->driver->setup(...func_get_args());
    }

    public function isStandby(): bool
    {
        $this->logger->info("{event}: {vars}", ['event' => 'isStandby', 'vars' => $this->logString(get_defined_vars())]);
        return $this->driver->isStandby();
    }

    public function send($contents, ?int $priority = null, $time = null, int $timeout = 0): ?string
    {
        $this->logger->info("{event}: {vars}", ['event' => 'send', 'vars' => $this->logString(get_defined_vars())]);
        $id = $this->driver->send($this->messageString($contents), $priority, $time, $timeout);
        $this->listener->onSend($id);
        if (!$time) {
            $this->driver->notify(1);
        }
        return $id;
    }

    public function sendBulk(iterable $contents, ?int $priority = null, $time = null, int $timeout = 0): array
    {
        $this->logger->info("{event}: {vars}", ['event' => 'sendBulk', 'vars' => $this->logString(get_defined_vars())]);
        $ids = [];
        foreach ($contents as $content) {
            $ids[] = $id = $this->driver->send($this->messageString($content), $priority, $time, $timeout);
            $this->listener->onSend($id);
        }
        if (!$time && $ids) {
            $this->driver->notify(count($ids));
        }
        return $ids;
    }

    public function transactional(callable $callable, ...$args)
    {
        return $this->driver->transactional($callable, ...$args);
    }

    public function notify(int $count = 1): int
    {
        $this->logger->info("{event}: {vars}", ['event' => 'notify', 'vars' => $this->logString(get_defined_vars())]);
        return $this->driver->notify($count);
    }

    public function cancel(?string $job_id = null, ?string $message = null): int
    {
        $this->logger->info("{event}: {vars}", ['event' => 'cancel', 'vars' => $this->logString(get_defined_vars())]);
        return $this->driver->cancel($job_id, $message);
    }

    public function clear(): int
    {
        $this->logger->notice("{event}: {vars}", ['event' => 'clear', 'vars' => $this->logString(get_defined_vars())]);
        return $this->driver->clear();
    }
}
