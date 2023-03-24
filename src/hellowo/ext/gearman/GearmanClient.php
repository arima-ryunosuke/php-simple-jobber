<?php
/**
 * @codeCoverageIgnore this is minimally emulation on windows for test
 */
namespace ryunosuke\hellowo\ext\gearman;

use Kicken\Gearman\Client;
use Kicken\Gearman\Job\JobPriority;

class GearmanClient
{
    private Client $client;

    private array $servers = [];
    private int   $timeout = -1;

    private function getClient(): Client
    {
        if (!isset($this->client)) {
            $this->client = new Client($this->servers);
            $this->client->setTimeout($this->timeout);
        }
        return $this->client;
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

    public function ping(string $workload): bool
    {
        return !!$workload;
    }

    public function doBackground(string $function_name, string $workload, string $unique = null): string
    {
        return $this->getClient()->submitBackgroundJob($function_name, $workload, JobPriority::NORMAL, $unique);
    }

    public function doLowBackground(string $function_name, string $workload, string $unique = null): string
    {
        return $this->getClient()->submitBackgroundJob($function_name, $workload, JobPriority::LOW, $unique);
    }

    public function doHighBackground(string $function_name, string $workload, string $unique = null): string
    {
        return $this->getClient()->submitBackgroundJob($function_name, $workload, JobPriority::HIGH, $unique);
    }
}
