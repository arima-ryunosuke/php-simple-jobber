<?php

namespace ryunosuke\Test\hellowo;

use ArrayListener;
use ArrayLogger;
use Exception;
use ryunosuke\hellowo\Client;
use ryunosuke\hellowo\Driver\AbstractDriver;
use ryunosuke\Test\AbstractTestCase;

class ClientTest extends AbstractTestCase
{
    function createDriver(&$data)
    {
        return new class($data) extends AbstractDriver {
            private $data;
            private $tmp;

            public function __construct(&$data)
            {
                $this->data = &$data;

                parent::__construct('', null);
            }

            protected function setup(bool $forcibly = false): void
            {
                $this->data = [];
            }

            protected function send(string $contents, ?int $priority = null, $time = null, int $timeout = 0): ?string
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

            protected function begin()
            {
                $this->tmp = $this->data;
            }

            protected function commit() { }

            protected function rollback()
            {
                $this->data = $this->tmp;
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
        $client->send('data-1', 1, 1, 2)->is(1);
        $client->notify()->is(0);
        $client->send('data-2', 2, 2, 2)->is(2);
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
                "timeout"  => null,
            ],
            [
                "contents" => "data-1",
                "priority" => 1,
                "time"     => 1.0,
                "timeout"  => 2,
            ],
            [
                "contents" => "data-2",
                "priority" => 2,
                "time"     => 2.0,
                "timeout"  => 2,
            ],
            [
                "contents" => '{"t":1234567890}',
                "priority" => null,
                "time"     => null,
                "timeout"  => null,
            ],
            [
                "contents" => "data-11",
                "priority" => 2,
                "time"     => null,
                "timeout"  => null,
            ],
            [
                "contents" => "data-12",
                "priority" => 2,
                "time"     => null,
                "timeout"  => null,
            ],
            [
                "contents" => '["data-json"]',
                "priority" => 2,
                "time"     => null,
                "timeout"  => null,
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

    function test_transactional()
    {
        $client = that(new Client([
            'driver'   => $this->createDriver($data),
            'logger'   => new ArrayLogger($logs),
            'listener' => new ArrayListener($events),
        ]));

        $client->setup();

        $client->transactional(function ($a, $b, $c) use ($client) {
            $client->sendBulk([$a, $b, $c]);
        }, 1, 2, 3);

        $data = [];
        try {
            $client->transactional(function ($a, $b, $c) use ($client) {
                $client->send($a);
                $client->send($b);
                throw new Exception($c);
            }, 1, 2, 3);
        }
        catch (Exception $e) {
            that($e->getMessage())->is(3);
        }
    }
}
