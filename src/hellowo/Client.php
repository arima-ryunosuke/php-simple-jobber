<?php

namespace ryunosuke\hellowo;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ryunosuke\hellowo\Driver\AbstractDriver;

class Client extends API
{
    private AbstractDriver  $driver;
    private LoggerInterface $logger;
    private Listener        $listener;

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
        if (isset($options['listener']) && !$options['listener'] instanceof Listener) {
            throw new InvalidArgumentException("listener must be Listener");
        }

        $this->driver   = $options['driver'];
        $this->logger   = $options['logger'] ?? new NullLogger();
        $this->listener = $options['listener'] ?? $this->NullListener();
    }

    public function setup(bool $forcibly = false): void
    {
        $this->logger->info('setup', get_defined_vars());
        $this->driver->setup(...func_get_args());
    }

    public function isStandby(): bool
    {
        $this->logger->info('isStandby', get_defined_vars());
        return $this->driver->isStandby();
    }

    public function send(string $contents, ?int $priority = null, ?float $delay = null): ?string
    {
        $this->logger->info('send', get_defined_vars());
        $id = $this->driver->send(...func_get_args());
        $this->listener->onSend($id);
        return $id;
    }

    public function notify(int $count = 1): int
    {
        $this->logger->info('notify', get_defined_vars());
        return $this->driver->notify($count);
    }

    public function clear(): int
    {
        $this->logger->notice('clear', get_defined_vars());
        return $this->driver->clear();
    }
}
