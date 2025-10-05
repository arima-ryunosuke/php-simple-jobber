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

    private array      $transport;
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
            'waittime'  => 10.0,
        ]);

        if (is_array($options['transport'])) {
            $this->transport = $options['transport'] + ['tube' => $options['tube']];
            parent::__construct("beanstalk {$options['transport']['host']}:{$options['transport']['port']}/{$options['tube']}");
        }
        else {
            $this->connection = $options['transport'];
            parent::__construct("beanstalk external");
        }

        $this->waittime = $options['waittime'];
    }

    protected function getConnection()
    {
        if (!isset($this->connection)) {
            $this->connection = Pheanstalk::create($this->transport['host'], $this->transport['port']);
            $this->connection->useTube($this->transport['tube']);
            $this->connection->watchOnly($this->transport['tube']);
        }

        return $this->connection;
    }

    protected function select(): Generator
    {
        $pheanstalkJob = $this->getConnection()->reserveWithTimeout(ceil($this->waittime));
        if ($pheanstalkJob) {
            $job    = $this->decode($pheanstalkJob->getData());
            $result = yield new Message($pheanstalkJob->getId(), $job['contents'], 0, 0);
            if ($result === null) {
                $this->getConnection()->delete($pheanstalkJob);
            }
            elseif (is_int($result) || is_float($result)) {
                $this->getConnection()->release($pheanstalkJob, PheanstalkInterface::DEFAULT_PRIORITY, ceil($result));
            }
            else {
                $this->getConnection()->bury($pheanstalkJob);
            }
        }
    }

    protected function error(Exception $e): bool
    {
        return $this->getConnection()->stats()->getResponseName() !== ResponseInterface::RESPONSE_OK;
    }

    protected function close(): void
    {
        unset($this->connection);

        gc_collect_cycles();
    }

    protected function send(string $contents, ?int $priority = null, $time = null, int $timeout = 0, ?int $ttr = null): ?string
    {
        $priority = $priority ?? PheanstalkInterface::DEFAULT_PRIORITY;
        $time     = $time ?? PheanstalkInterface::DEFAULT_DELAY;
        $ttr      = $ttr ?? PheanstalkInterface::DEFAULT_TTR;

        // beanstalk's priority: 0 ~ 4294967295 (high ~ low)
        $job = $this->getConnection()->put($this->encode(['contents' => $contents]), 4294967295 - $priority, ceil($this->getDelay($time)), $ttr);
        return (string) $job->getId();
    }

    protected function clear(): int
    {
        $count = 0;
        while ($job = $this->getConnection()->reserveWithTimeout(0)) {
            $count++;
            $this->getConnection()->delete($job);
        }
        return $count;
    }
}
