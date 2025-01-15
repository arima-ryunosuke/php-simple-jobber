<?php

namespace ryunosuke\hellowo\Driver;

use Exception;
use GearmanClient;
use GearmanJob;
use GearmanWorker;
use Generator;
use ryunosuke\hellowo\ext\gearman;
use ryunosuke\hellowo\Message;

/**
 * architecture:
 * nothing special, simply use Gearman.
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

    /** @var GearmanJob[] */
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
        $this->worker->setTimeout($options['waittime'] * 1000);

        parent::__construct("gearman {$options['transport']['host']}:{$options['transport']['port']}/{$options['function']}");
    }

    protected function daemonize(): void
    {
        $this->worker->addServer($this->host, $this->port);
        $this->worker->addFunction($this->function, function (GearmanJob $job) {
            $this->buffer[$job->unique()] = $job;
        });

        parent::daemonize();
    }

    protected function select(): Generator
    {
        if (!$this->buffer) {
            $this->worker->work();
        }

        foreach ($this->buffer as $id => $job) {
            $retry = yield new Message($job->unique(), $job->workload());
            if ($retry === null) {
                unset($this->buffer[$id]);
            }
            else {
                usleep($retry * 1000 * 1000);
                unset($this->buffer[$id]);
                $this->client->doBackground($this->function, $job->workload());
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
            $this->client->doBackground($this->function, $job->workload());
        }
        unset($this->client);

        $this->worker->unregisterAll();
        unset($this->worker);

        gc_collect_cycles();
    }

    protected function send(string $contents, ?int $priority = null, ?float $delay = null): ?string
    {
        assert($priority === null || (0 <= $priority && $priority <= 2));

        $methods = [
            0 => 'doLowBackground',
            1 => 'doBackground',
            2 => 'doHighBackground',
        ];
        return $this->client->{$methods[$priority ?? 1]}($this->function, $contents);
    }

    protected function clear(): int
    {
        $count    = 0;
        $consumer = new GearmanWorker();
        $consumer->setTimeout(100);
        $consumer->addServer($this->host, $this->port);
        $consumer->addFunction($this->function, function (GearmanJob $job) use (&$count) {
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
}
