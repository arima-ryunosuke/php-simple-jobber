<?php

use Psr\Log\AbstractLogger;
use ryunosuke\hellowo\Client;
use ryunosuke\hellowo\Driver\AbstractDriver;
use ryunosuke\hellowo\Exception\RetryableException;
use ryunosuke\hellowo\Message;
use ryunosuke\hellowo\Worker;

require_once __DIR__ . '/../vendor/autoload.php';

ini_set('memory_limit', 30 * 1024 * 1024);
ini_set('log_errors', true);
ini_set('error_log', 'php://stderr');

// create driver
$driver = (function (string $url) {
    $xmls       = array_filter([__DIR__ . '/phpunit.xml', __DIR__ . '/phpunit.xml.dist'], 'file_exists');
    $phpuni_xml = simplexml_load_file($xmls[0]);
    $constants  = [];
    foreach ($phpuni_xml->php->const as $sxe) {
        $constants[(string) $sxe['name']] = (string) $sxe['value'];
    }
    if (isset($constants[$constname = (strtoupper($url) . '_URL')])) {
        $url = $constants[$constname];
    }

    return AbstractDriver::create($url, [
        'waittime'   => 0.5,
        'waitmode'   => 'php',
        'sharedFile' => sys_get_temp_dir() . '/jobs.txt',
    ]);
})($argv[1] ?? '');

// run by mode
(function (AbstractDriver $driver, string $mode, string $contents, ?int $priority, ?float $delay) {
    switch ($mode) {
        case 'cancel':
            $client = new Client([
                'driver' => $driver,
            ]);
            $client->setup(false);
            $client->cancel(null, $contents);
            return;

        case 'clear':
            $client = new Client([
                'driver' => $driver,
            ]);
            $client->setup(true);
            $client->clear();
            return;

        case 'client':
            $client = new Client([
                'driver' => $driver,
            ]);
            $client->send($contents, $priority, $delay);
            return;

        case 'worker':
            $worker = new Worker([
                'work'   => function (Message $message): string {
                    if ($message->getContents() === 'heavy') {
                        return strlen(str_repeat($message->getContents(), 10 * 1024 * 1024));
                    }
                    if ($message->getContents() === 'retry' && $message->getRetry() < 3) {
                        throw new RetryableException(0.1);
                    }
                    fwrite(STDOUT, "$message\n");
                    return $message;
                },
                'driver' => $driver,
                'logger' => new class() extends AbstractLogger {
                    public function log($level, $message, array $context = [])
                    {
                        fwrite(STDERR, date('Y/m/d H:i:s') . ":$message\n");
                    }
                },
            ]);
            $worker->start();
            return;
    }
})($driver, $argv[2] ?? '', $argv[3] ?? '', strlen($argv[4] ?? '') ? $argv[4] : null, strlen($argv[5] ?? '') ? $argv[5] : null);
