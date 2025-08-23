<?php

namespace ryunosuke\hellowo\Driver;

use Closure;
use Exception;
use Generator;
use ryunosuke\hellowo\ext\gearman;
use ryunosuke\hellowo\ext\gearman\GearmanClient;
use ryunosuke\hellowo\ext\gearman\GearmanJob;
use ryunosuke\hellowo\ext\gearman\GearmanWorker;
use ryunosuke\hellowo\Message;

/**
 * architecture:
 * delay is implemented in-memory, therefore lost job at sudden death
 */
class GearmanDriver extends AbstractDriver
{
    public static function isEnabled(): bool
    {
        return class_exists(GearmanWorker::class);
    }

    protected static function normalizeParams(array $params): array
    {
        return [
            'function' => $params['path'] ?? null,
        ];
    }

    private string $host;
    private int    $port;
    private string $function;

    private GearmanClient $client;
    private GearmanWorker $worker;

    private array $buffer = [];

    public function __construct(array $options)
    {
        $options = self::normalizeOptions($options, [
            // gearman DSN
            'transport' => [
                'host' => '127.0.0.1',
                'port' => 4730,
            ],
            // gearman function name
            'function'  => 'hellowo',
            // one cycle wait time
            'waittime'  => 60.0,
        ]);

        $this->host     = $options['transport']['host'];
        $this->port     = $options['transport']['port'];
        $this->function = $options['function'];

        // client
        $this->client = new GearmanClient();
        $this->client->addServer($options['transport']['host'], $options['transport']['port']);

        // worker
        $this->worker = new GearmanWorker();
        $this->worker->setTimeout(ceil($options['waittime'] * 1000));

        parent::__construct("gearman {$options['transport']['host']}:{$options['transport']['port']}/{$options['function']}");
    }

    protected function daemonize(): void
    {
        $this->worker->addServer($this->host, $this->port);
        $this->worker->addFunction($this->function, function ($job) {
            /** @var GearmanJob $job */
            $this->buffer[$job->handle()] = json_decode($job->workload(), true) ?? ['contents' => $job->workload(), 'priority' => null, 'start_at' => microtime(true), 'retry' => 0]; // for compatible
        });

        parent::daemonize();
    }

    protected function select(): Generator
    {
        if (!$this->buffer) {
            $this->worker->work();

            foreach ($this->buffer as $id => $job) {
                if ($job['start_at'] > microtime(true)) {
                    unset($this->buffer[$id]);
                    $this->doBackgroundMethod($job['priority'])(json_encode($job));
                }
            }
        }

        foreach ($this->buffer as $id => $job) {
            $retry = yield new Message($id, $job['contents'], $job['retry']);
            if ($retry === null) {
                unset($this->buffer[$id]);
            }
            else {
                unset($this->buffer[$id]);
                $job['retry']++;
                $job['start_at'] = microtime(true) + $retry;
                $this->doBackgroundMethod($job['priority'])(json_encode($job));
            }
            return;
        }
    }

    protected function error(Exception $e): bool
    {
        return !$this->client->ping('ping');
    }

    protected function close(): void
    {
        foreach ($this->buffer as $job) {
            $this->doBackgroundMethod($job['priority'])(json_encode($job));
        }
        unset($this->client);

        $this->worker->unregisterAll();
        unset($this->worker);

        gc_collect_cycles();
    }

    protected function send(string $contents, ?int $priority = null, ?float $delay = null): ?string
    {
        return $this->doBackgroundMethod($priority)(json_encode([
            'contents' => $contents,
            'priority' => $priority,
            'start_at' => microtime(true) + ceil($delay ?? 0),
            'retry'    => 0,
        ]));
    }

    protected function clear(): int
    {
        $count    = 0;
        $consumer = new GearmanWorker();
        $consumer->setTimeout(100);
        $consumer->addServer($this->host, $this->port);
        $consumer->addFunction($this->function, function ($job) use (&$count) {
            $count++;
        });
        while ($consumer->work()) {
            if ($consumer->returnCode() === gearman::GEARMAN_NO_JOBS) {
                break;
            }
        }
        $consumer->unregisterAll();
        return $count;
    }

    protected function doBackgroundMethod(?int $priority = null): Closure
    {
        assert($priority === null || (0 <= $priority && $priority <= 2));

        $methods = [
            0 => 'doLowBackground',
            1 => 'doBackground',
            2 => 'doHighBackground',
        ];
        return fn(string $workload) => $this->client->{$methods[$priority ?? 1]}($this->function, $workload);
    }
}
