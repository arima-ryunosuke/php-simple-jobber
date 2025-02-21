<?php
/**
 * @codeCoverageIgnore this is minimally emulation on windows for test
 */
namespace ryunosuke\hellowo\ext\gearman;

use Kicken\Gearman\Exception\TimeoutException;
use Kicken\Gearman\Job\WorkerJob;
use Kicken\Gearman\Worker;
use ryunosuke\hellowo\ext\gearman;

// This constant is only for property assignment dynamically(expression) and has no other meaning
foreach ([
    'GEARMAN_NO_JOBS'               => 35,
    'GEARMAN_ECHO_DATA_CORRUPTION'  => 36,
    'GEARMAN_NEED_WORKLOAD_FN'      => 37,
    'GEARMAN_PAUSE'                 => 38,
    'GEARMAN_UNKNOWN_STATE'         => 39,
    'GEARMAN_PTHREAD'               => 40,
    'GEARMAN_PIPE_EOF'              => 41,
    'GEARMAN_QUEUE_ERROR'           => 42,
    'GEARMAN_FLUSH_DATA'            => 43,
    'GEARMAN_SEND_BUFFER_TOO_SMALL' => 44,
    'GEARMAN_IGNORE_PACKET'         => 45,
    'GEARMAN_UNKNOWN_OPTION'        => 46,
    'GEARMAN_TIMEOUT'               => 47,
    'GEARMAN_MAX_RETURN'            => 49,
] as $name => $value) {
    define(__NAMESPACE__ . "\\$name", defined($name) ? constant($name) : $value);
}

if (class_exists(\GearmanWorker::class)) {
    class GearmanWorker extends \GearmanWorker { }
}
else {
    class GearmanWorker
    {
        private Worker $worker;

        private array $servers = [];
        private int   $timeout = -1;
        private int   $returnCode;

        private function getWorker(): Worker
        {
            if (!isset($this->worker)) {
                $this->worker = new Worker($this->servers);
                $this->worker->setTimeout($this->timeout);
            }
            return $this->worker;
        }

        public function addServer(string $host = '127.0.0.1', int $port = 4730): bool
        {
            $this->servers[] = "$host:$port";
            return true;
        }

        public function setTimeout(int $timeout): bool
        {
            $this->timeout = $timeout;
            return true;
        }

        public function addFunction(string $function_name, callable $function, &$context = null, int $timeout = null): bool
        {
            $this->getWorker()->registerFunction($function_name, fn(WorkerJob $job) => $function(new GearmanJob($job)), $timeout);
            return true;
        }

        public function work(): bool
        {
            try {
                $this->getWorker()->work();
            }
            catch (TimeoutException $e) {
                $this->returnCode = gearman::GEARMAN_NO_JOBS;
            }
            return true;
        }

        public function returnCode(): int
        {
            return $this->returnCode;
        }

        public function unregisterAll(): bool
        {
            (fn() => $this->workerList = [])->bindTo($this->getWorker(), Worker::class);
            return true;
        }
    }
}
