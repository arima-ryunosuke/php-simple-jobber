<?php

namespace ryunosuke\Test\hellowo;

use ArrayListener;
use ArrayLogger;
use ryunosuke\hellowo\API;
use ryunosuke\hellowo\Client;
use ryunosuke\hellowo\Driver\AbstractDriver;
use ryunosuke\hellowo\ext\pcntl;
use ryunosuke\Test\AbstractTestCase;

class ClientTest extends AbstractTestCase
{
    function createDriver(&$data)
    {
        return new class($data) extends AbstractDriver {
            private $data;

            public function __construct(&$data)
            {
                $this->data = &$data;

                parent::__construct('');
            }

            protected function setup(bool $forcibly = false): void
            {
                $this->data = [];
            }

            protected function send(string $contents, ?int $priority = null, ?float $delay = null): ?string
            {
                $this->data[] = get_defined_vars();
                return count($this->data);
            }

            protected function clear(): int
            {
                $result     = count($this->data);
                $this->data = [];
                return $result;
            }
        };
    }

    function test_notifyLocal()
    {
        if (DIRECTORY_SEPARATOR === '/') {
            $this->markTestSkipped();
        }

        srand(1);
        $processdir = API::$processDirectory;

        mkdir("$processdir/9999", 0777, true);
        file_put_contents("$processdir/9999/cmdline", '#hellowo');

        mkdir("$processdir/1234", 0777, true);
        file_put_contents("$processdir/1234/cmdline", '#hellowo');

        that(Client::class)::notifyLocal(1)->is([1234]);

        that("$processdir/1234/signal")->fileEquals(pcntl::SIGUSR1 . "\n");
        that("$processdir/9999/signal")->fileNotExists();

        that(Client::class)::notifyLocal(99)->is([1234, 9999]);

        that("$processdir/1234/signal")->fileEquals(pcntl::SIGUSR1 . "\n" . pcntl::SIGUSR1 . "\n");
        that("$processdir/9999/signal")->fileEquals(pcntl::SIGUSR1 . "\n");
    }

    function test___construct()
    {
        that(Client::class)->new([])->wasThrown('driver is required');

        that(Client::class)->new([
            'driver'   => $this->createDriver($data),
            'listener' => 'hoge',
        ])->wasThrown('listener must be Listener');
    }

    function test_all()
    {
        $processdir = sys_get_temp_dir() . '/hellowo/process';
        array_map('unlink', glob("$processdir/*"));

        $client = that(new Client([
            'driver'   => $this->createDriver($data),
            'logger'   => new ArrayLogger($logs),
            'listener' => new ArrayListener($events),
        ]));

        $client->setup();
        $client->driver->data->isArray();

        $client->isStandby()->isFalse();

        $client->send('data-0')->is(1);
        $client->notify()->is(0);
        $client->send('data-1', 1, 1)->is(2);
        $client->notify()->is(0);
        $client->send('data-2', 2, 2)->is(3);
        $client->notify()->is(0);

        that($data)->is([
            [
                "contents" => "data-0",
                "priority" => null,
                "delay"    => null,
            ],
            [
                "contents" => "data-1",
                "priority" => 1,
                "delay"    => 1.0,
            ],
            [
                "contents" => "data-2",
                "priority" => 2,
                "delay"    => 2.0,
            ],
        ]);

        that($logs)->matchesCountEquals([
            '#^setup#'  => 1,
            '#^send#'   => 3,
            '#^notify#' => 3,
        ]);

        that($events)->is([
            "send" => ["1", "2", "3"],
        ]);

        $client->clear()->is(3);
    }
}
