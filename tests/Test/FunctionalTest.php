<?php

namespace ryunosuke\Test;

use ryunosuke\hellowo\Driver\BeanstalkDriver;
use ryunosuke\hellowo\Driver\FileSystemDriver;
use ryunosuke\hellowo\Driver\GearmanDriver;
use ryunosuke\hellowo\Driver\MySqlDriver;
use ryunosuke\hellowo\Driver\PostgreSqlDriver;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class FunctionalTest extends AbstractTestCase
{
    public function provideDriver()
    {
        $drivers = [
            'filesystem' => [
                'driver'   => FileSystemDriver::class,
                'dsn'      => defined('FILESYSTEM_URL') ? FILESYSTEM_URL : null,
                'priority' => true,
                'delay'    => true,
                'retry'    => true,
                'cancel'   => true,
                'fork'     => true,
            ],
            'beanstalk'  => [
                'driver'   => BeanstalkDriver::class,
                'dsn'      => defined('BEANSTALK_URL') ? BEANSTALK_URL : null,
                'priority' => true,
                'delay'    => true,
                'retry'    => false,
                'cancel'   => false,
                'fork'     => true,
            ],
            'gearman'    => [
                'driver'   => GearmanDriver::class,
                'dsn'      => defined('GEARMAN_URL') ? GEARMAN_URL : null,
                'priority' => true,
                'delay'    => true,
                'retry'    => true,
                'cancel'   => false,
                'fork'     => false,
            ],
            'mysql'      => [
                'driver'   => MySqlDriver::class,
                'dsn'      => defined('MYSQL_URL') ? MYSQL_URL : null,
                'priority' => true,
                'delay'    => true,
                'retry'    => true,
                'cancel'   => true,
                'fork'     => true,
            ],
            'pgsql'      => [
                'driver'   => PostgreSqlDriver::class,
                'dsn'      => defined('PGSQL_URL') ? PGSQL_URL : null,
                'priority' => true,
                'delay'    => true,
                'retry'    => true,
                'cancel'   => true,
                'fork'     => true,
            ],
        ];
        $drivers = array_filter($drivers, function ($driver) {
            if ($driver['dsn'] === null) {
                return false;
            }
            if (!$driver['driver']::isEnabled()) {
                return false;
            }
            return true;
        });

        return array_map(fn($options) => [__DIR__ . "/../api.php", $options], $drivers);
    }

    /**
     * @dataProvider provideDriver
     */
    function test_multiple_request_work($script, $options)
    {
        // ready
        (new Process([PHP_BINARY, $script, $options['dsn'], 'clear']))->run();
        $data = array_map(fn($n) => "data-$n", range(10, 15));

        // start worker
        $worker = new Process([PHP_BINARY, $script, $options['dsn'], 'worker:work']);
        $worker->setTimeout(10);
        $worker->start();

        try {
            // send multiple events
            $clients = array_map(fn($datum, $n) => new Process([PHP_BINARY, $script, $options['dsn'], 'client', $datum, '', 3]), $data, array_keys($data));
            array_map(fn($client) => $client->start(), $clients);
            array_map(fn($client) => $client->wait(), $clients);
            foreach ($clients as $client) {
                that($client->getOutput())->is('');
                that($client->getErrorOutput())->is('');
            }

            // send first/final/retry event
            $client = new Process([PHP_BINARY, $script, $options['dsn'], 'client', 'first', 2, 0]);
            $client->run();
            $client = new Process([PHP_BINARY, $script, $options['dsn'], 'client', 'final', 0, 6]);
            $client->run();
            $initialData = ['first', 'final'];
            if ($options['retry']) {
                $client = new Process([PHP_BINARY, $script, $options['dsn'], 'client', 'retry', 0, 0]);
                $client->run();
                $initialData[] = 'retry';
            }
            if ($options['cancel']) {
                $client = new Process([PHP_BINARY, $script, $options['dsn'], 'client', 'cancel', 0, 2]);
                $client->run();
                $client = new Process([PHP_BINARY, $script, $options['dsn'], 'cancel', 'cancel']);
                $client->run();
            }

            // wait final request
            while (strpos($worker->getOutput(), 'final') === false) {
                $worker->getStatus();
                $worker->checkTimeout();
                usleep(50 * 1000);
            }

            $output = $worker->getOutput();
            $error  = $worker->getErrorOutput();

            // assert
            that($output)->containsAll($data);
            that(substr_count($output, "data-"))->as($output)->is(count($data));
            that(substr_count($error, "done:"))->as($error)->is(count($data) + count($initialData));
            if ($options['delay']) {
                that(trim($output))->as('"first" should have been reached first by delay.')->prefixIs('first');
                that(trim($output))->as('"final" should have been reached final by delay.')->suffixIs('final');
            }
        }
        catch (ProcessTimedOutException $ex) {
            $this->fail($worker->getErrorOutput());
        }
        finally {
            $worker->stop();
        }
    }

    /**
     * @dataProvider provideDriver
     */
    function test_multiple_request_fork($script, $options)
    {
        if (!extension_loaded('pcntl') || !$options['fork']) {
            $this->markTestSkipped();
        }

        // ready
        (new Process([PHP_BINARY, $script, $options['dsn'], 'clear']))->run();
        $data = array_map(fn($n) => "data-$n", range(10, 15));

        // start worker
        $worker = new Process([PHP_BINARY, $script, $options['dsn'], 'worker:fork']);
        $worker->setTimeout(10);
        $worker->start();

        try {
            // send multiple events
            $clients = array_map(fn($datum, $n) => new Process([PHP_BINARY, $script, $options['dsn'], 'client', $datum, '', 3]), $data, array_keys($data));
            array_map(fn($client) => $client->start(), $clients);
            array_map(fn($client) => $client->wait(), $clients);
            foreach ($clients as $client) {
                that($client->getOutput())->is('');
                that($client->getErrorOutput())->is('');
            }

            // send first/final/retry event
            $client = new Process([PHP_BINARY, $script, $options['dsn'], 'client', 'first', 2, 0]);
            $client->run();
            $client = new Process([PHP_BINARY, $script, $options['dsn'], 'client', 'final', 0, 6]);
            $client->run();
            $initialData = ['first', 'final'];
            if ($options['retry']) {
                $client = new Process([PHP_BINARY, $script, $options['dsn'], 'client', 'retry', 0, 0]);
                $client->run();
                $initialData[] = 'retry';
            }
            if ($options['cancel']) {
                $client = new Process([PHP_BINARY, $script, $options['dsn'], 'client', 'cancel', 0, 2]);
                $client->run();
                $client = new Process([PHP_BINARY, $script, $options['dsn'], 'cancel', 'cancel']);
                $client->run();
            }

            // wait final request
            while (strpos($worker->getOutput(), 'final') === false) {
                $worker->getStatus();
                $worker->checkTimeout();
                usleep(50 * 1000);
            }

            $output = $worker->getOutput();
            $error  = $worker->getErrorOutput();

            // assert
            that($output)->containsAll($data);
            that(substr_count($output, "data-"))->as($output)->is(count($data));
            that(substr_count($error, "done:"))->as($error)->is(count($data) + count($initialData));
            if ($options['delay']) {
                that(trim($output))->as('"first" should have been reached first by delay.')->prefixIs('first');
                that(trim($output))->as('"final" should have been reached final by delay.')->suffixIs('final');
            }
        }
        catch (ProcessTimedOutException $ex) {
            $this->fail($worker->getErrorOutput());
        }
        finally {
            $worker->stop();
        }
    }
}
