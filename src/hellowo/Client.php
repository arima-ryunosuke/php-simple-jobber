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
        $this->logger->info("setup: {$this->logString(get_defined_vars())}");
        $this->driver->setup(...func_get_args());
    }

    public function isStandby(): bool
    {
        $this->logger->info("isStandby: {$this->logString(get_defined_vars())}");
        return $this->driver->isStandby();
    }

    public function send(string $contents, ?int $priority = null, ?float $delay = null): ?string
    {
        $this->logger->info("send: {$this->logString(get_defined_vars())}");
        $id = $this->driver->send(...func_get_args());
        $this->listener->onSend($id);
        return $id;
    }

    public function sendJson($contents, ?int $priority = null, ?float $delay = null): ?string
    {
        return $this->send(json_encode($contents, JSON_UNESCAPED_UNICODE), $priority, $delay);
    }

    public function notify(int $count = 1): int
    {
        $this->logger->info("notify: {$this->logString(get_defined_vars())}");
        return $this->driver->notify($count);
    }

    public function clear(): int
    {
        $this->logger->notice("clear: {$this->logString(get_defined_vars())}");
        return $this->driver->clear();
    }
}
