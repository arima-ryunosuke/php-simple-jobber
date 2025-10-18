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
    private string $function_dead;

    private GearmanClient $client;
    private GearmanWorker $worker;

    private array $buffer = [];
    private array $afters = [];

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
            'waittime'  => 10.0,
        ]);

        $this->host          = $options['transport']['host'];
        $this->port          = $options['transport']['port'];
        $this->function      = $options['function'];
        $this->function_dead = $options['function'] . '_dead';

        // client
        $this->client = new GearmanClient();
        $this->client->addServer($options['transport']['host'], $options['transport']['port']);

        // worker
        $this->worker = new GearmanWorker();
        $this->worker->setTimeout(ceil($options['waittime'] * 1000));

        parent::__construct("gearman {$options['transport']['host']}:{$options['transport']['port']}/{$options['function']}", $options['logger'] ?? null);
    }

    protected function daemonize(): void
    {
        $this->logger->info('{event}: {host}:{port}/{function}', ['event' => 'connect', 'host' => $this->host, 'port' => $this->port, 'function' => $this->function]);
        $this->worker->addServer($this->host, $this->port);
        $this->worker->addFunction($this->function, function ($job) {
            /** @var GearmanJob $job */
            $this->buffer[$job->handle()] = $this->decode($job->workload());
        });

        parent::daemonize();
    }

    protected function select(): Generator
    {
        if (!$this->buffer) {
            foreach ($this->afters as $id => $job) {
                if ($job['start_at'] <= microtime(true)) {
                    unset($this->afters[$id]);
                    $this->buffer[$id] = $job;
                }
            }

            $this->worker->work();

            foreach ($this->buffer as $id => $job) {
                if ($job['start_at'] > microtime(true)) {
                    unset($this->buffer[$id]);
                    $this->afters[$id] = $job;
                }
            }
        }

        foreach ($this->buffer as $id => $job) {
            $result = yield new Message($id, $job['contents'], $job['retry'], $job['timeout']);
            if ($result === null) {
                unset($this->buffer[$id]);
            }
            elseif (is_int($result) || is_float($result)) {
                unset($this->buffer[$id]);
                $job['retry']++;
                $job['start_at']   = microtime(true) + $result;
                $this->afters[$id] = $job;
            }
            else {
                unset($this->buffer[$id]);
                $job['error'] = (string) $result;
                $this->doBackgroundMethod($job['priority'])($this->function_dead, $this->encode($job));
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
        foreach ($this->afters + $this->buffer as $job) {
            $this->doBackgroundMethod($job['priority'])($this->function, $this->encode($job));
        }
        unset($this->client);

        $this->worker->unregisterAll();
        unset($this->worker);

        gc_collect_cycles();
    }

    protected function send(string $contents, ?int $priority = null, $time = null, int $timeout = 0): ?string
    {
        return $this->doBackgroundMethod($priority)($this->function, $this->encode([
            'contents' => $contents,
            'priority' => $priority,
            'start_at' => microtime(true) + ceil($this->getDelay($time)),
            'timeout'  => $timeout,
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
        return fn(string $function, string $workload) => $this->client->{$methods[$priority ?? 1]}($function, $workload);
    }
}
