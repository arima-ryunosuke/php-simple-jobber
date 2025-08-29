<?php

namespace ryunosuke\Test\hellowo;

use ArrayListener;
use ArrayLogger;
use ryunosuke\hellowo\Client;
use ryunosuke\hellowo\Driver\AbstractDriver;
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

            protected function send(string $contents, ?int $priority = null, $time = null): ?string
            {
                $this->data[] = get_defined_vars();
                return count($this->data) - 1;
            }

            protected function cancel(?string $job_id = null, ?string $contents = null): int
            {
                unset($this->data[$job_id]);
                return 1;
            }

            protected function clear(): int
            {
                $result     = count($this->data);
                $this->data = [];
                return $result;
            }
        };
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

        $client->send('data-0')->is(0);
        $client->notify()->is(0);
        $client->send('data-1', 1, 1)->is(1);
        $client->notify()->is(0);
        $client->send('data-2', 2, 2)->is(2);
        $client->notify()->is(0);
        $client->send(['t' => 1234567890])->is(3);
        $client->notify()->is(0);
        $client->sendBulk((function () {
            yield 'data-11';
            yield 'data-12';
            yield ['data-json'];
        })(), 2)->is([4, 5, 6]);

        $client->cancel($client->send('data-cancel'))->is(1);

        that($data)->is([
            [
                "contents" => "data-0",
                "priority" => null,
                "time"     => null,
            ],
            [
                "contents" => "data-1",
                "priority" => 1,
                "time"     => 1.0,
            ],
            [
                "contents" => "data-2",
                "priority" => 2,
                "time"     => 2.0,
            ],
            [
                "contents" => '{"t":1234567890}',
                "priority" => null,
                "time"     => null,
            ],
            [
                "contents" => "data-11",
                "priority" => 2,
                "time"     => null,
            ],
            [
                "contents" => "data-12",
                "priority" => 2,
                "time"     => null,
            ],
            [
                "contents" => '["data-json"]',
                "priority" => 2,
                "time"     => null,
            ],
        ]);

        that($logs)->matchesCountEquals([
            '#^setup:#'    => 1,
            '#^send:#'     => 5,
            '#^sendBulk:#' => 1,
            '#^notify:#'   => 4,
        ]);

        that($events)->is([
            "send" => ["0", "1", "2", "3", "4", "5", "6", "7"],
        ]);

        $client->clear()->is(7);
    }
}
