<?php

namespace ryunosuke\hellowo\Driver;

use Exception;
use Generator;
use Pheanstalk\Contract\PheanstalkInterface;
use Pheanstalk\Contract\ResponseInterface;
use Pheanstalk\Pheanstalk;
use ryunosuke\hellowo\Message;

/**
 * architecture:
 * nothing special, simply use Beanstalk.
 */
class BeanstalkDriver extends AbstractDriver
{
    public static function isEnabled(): bool
    {
        return class_exists(Pheanstalk::class);
    }

    protected static function normalizeParams(array $params): array
    {
        return [
            'tube' => $params['path'] ?? null,
        ];
    }

    private Pheanstalk $connection;

    private float $waittime;

    public function __construct(array $options)
    {
        $options = self::normalizeOptions($options, [
            // beanstalkd DSN
            'transport' => [
                'host' => '127.0.0.1',
                'port' => 11300,
            ],
            // beanstalkd tube name
            'tube'      => 'hellowo',
            // one cycle wait time
            'waittime'  => 60.0,
        ]);

        $this->connection = Pheanstalk::create($options['transport']['host'], $options['transport']['port']);
        $this->connection->useTube($options['tube']);
        $this->connection->watchOnly($options['tube']);

        $this->waittime = $options['waittime'];

        parent::__construct("beanstalk {$options['transport']['host']}:{$options['transport']['port']}/{$options['tube']}");
    }

    protected function select(): Generator
    {
        $job = $this->connection->reserveWithTimeout(ceil($this->waittime));
        if ($job) {
            $retry = yield new Message($job->getId(), $job->getData(), 0);
            if ($retry === null) {
                $this->connection->delete($job);
            }
            else {
                $this->connection->release($job, PheanstalkInterface::DEFAULT_PRIORITY, ceil($retry));
            }
        }
    }

    protected function error(Exception $e): bool
    {
        return $this->connection->stats()->getResponseName() !== ResponseInterface::RESPONSE_OK;
    }

    protected function close(): void
    {
        unset($this->connection);

        gc_collect_cycles();
    }

    protected function send(string $contents, ?int $priority = null, ?float $delay = null, ?int $ttr = null): ?string
    {
        $priority = $priority ?? PheanstalkInterface::DEFAULT_PRIORITY;
        $delay    = $delay ?? PheanstalkInterface::DEFAULT_DELAY;
        $ttr      = $ttr ?? PheanstalkInterface::DEFAULT_TTR;

        // beanstalk's priority: 0 ~ 4294967295 (high ~ low)
        $job = $this->connection->put($contents, 4294967295 - $priority, ceil($delay), $ttr);
        return (string) $job->getId();
    }

    protected function clear(): int
    {
        $count = 0;
        while ($job = $this->connection->reserveWithTimeout(0)) {
            $count++;
            $this->connection->delete($job);
        }
        return $count;
    }
}
