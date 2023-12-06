<?php

namespace ryunosuke\Test\hellowo\Driver;

use Exception;
use mysqli;
use ryunosuke\hellowo\Driver\AbstractDriver;
use ryunosuke\hellowo\Driver\MySqlDriver;
use ryunosuke\Test\AbstractTestCase;

class MySqlDriverTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        if (!defined('MYSQL_URL') || !MySqlDriver::isEnabled()) {
            $this->markTestSkipped();
        }
    }

    function test_all()
    {
        $driver = that(AbstractDriver::create(MYSQL_URL, [
            'waittime' => 1,
            'waitmode' => 'php',
        ]));
        $driver->setup(true);
        $driver->daemonize();

        $driver->clear();
        $driver->send('Z1', 1);
        $driver->send('Z2', 1);
        $driver->clear()->is(2);

        $driver->send('B', 1);
        $driver->send('A', 2);
        $driver->send('X', null, 10);

        $message = $driver->select();
        $message->getOriginal()->isArray();
        $message->getId()->isNumeric();
        $message->getContents()->is('A');
        $driver->done($message);

        $message = $driver->select();
        $message->getOriginal()->isArray();
        $message->getId()->isNumeric();
        $message->getContents()->is('B');
        $driver->done($message);

        $driver->select()->isNull();

        $driver->send('C', 1);
        $message = $driver->select();
        $message->getContents()->is('C');
        $driver->retry($message, 2);
        $message = $driver->select();
        $message->isNull();
        sleep(2);
        $message = $driver->select();
        $message->getContents()->is('C');
        $driver->done($message);

        $driver->error(new Exception())->isFalse();

        $driver->close();
    }

    function test_transaction()
    {
        $driver = that(AbstractDriver::create(MYSQL_URL));
        $driver->setup(false);
        $driver->clear();

        $original = $driver->table->return();

        $driver->send('A');

        $driver->table = 't_undefined';
        $driver->select()->wasThrown(" doesn't exist");

        $driver->table = $original;
        $message       = $driver->select();
        $driver->send('X');
        $driver->execute("SELECT * FROM {$original}")->count(2);
        $driver->table = 't_undefined';
        $driver->done($message)->wasThrown(" doesn't exist");
        $driver->execute("SELECT * FROM {$original}")->count(1); // rollbacked

        $driver->table = $original;
        $message       = $driver->select();
        $driver->send('X');
        $driver->execute("SELECT * FROM {$original}")->count(2);
        $driver->table = 't_undefined';
        $driver->retry($message, 10)->wasThrown(" doesn't exist");
        $driver->execute("SELECT * FROM {$original}")->count(1); // rollbacked

        $driver->close();
    }

    function test_sleep_sql()
    {
        $driver = that(AbstractDriver::create(MYSQL_URL, [
            'waittime' => 2,
            'waitmode' => 'sql',
        ]));
        $driver->setup(true);

        /** @var mysqli $connection */
        $connection = $driver->var('connection');

        $url = MYSQL_URL;
        $cid = $connection->thread_id;
        $this->backgroundTask(function () use ($url, $cid) {
            $connection = (fn() => $this->connection)->call(AbstractDriver::create($url));
            while (true) {
                $connection->query("KILL QUERY $cid");
                usleep(100 * 1000);
            }
        });

        $driver->notify()->is(0);
        $driver->notify(1)->is(0);
        $time = microtime(true);
        $driver->sleep();
        that(microtime(true) - $time)->lessThan(2);

        $driver->close();
    }

    function test_recover()
    {
        $tmpdriver = that(AbstractDriver::create(MYSQL_URL, [
            'heartbeat' => 0,
        ]));

        $tmpdriver->recover()->is([]);
        $tmpdriver->processlist()->is([]);

        $otherCid = $tmpdriver->var('connection')->thread_id;

        $driver = that(new class([
            'transport' => that(AbstractDriver::create(MYSQL_URL))->var('connection'),
            'heartbeat' => 10,
        ]) extends MySqlDriver {
            public $processlist = [];

            protected function processlist(): array
            {
                return $this->processlist;
            }
        });

        // no timer
        $driver->heartbeatTimer->isNot(0);
        $driver->recover()->isEmpty();

        // TIME < 10 is empty
        $driver->heartbeatTimer = 0;
        $driver->processlist    = [
            [
                'ID'      => $otherCid,
                'HOST'    => '169.254.169.254',
                'TIME'    => 7,
                'COMMAND' => 'Sleep',
            ],
        ];
        $driver->recover()->is([]);

        // TIME > 10 is killed
        $driver->heartbeatTimer = 0;
        $driver->processlist    = [
            [
                'ID'      => $otherCid,
                'HOST'    => '169.254.169.254',
                'TIME'    => 12,
                'COMMAND' => 'Sleep',
            ],
        ];
        $driver->recover()->is([
            $otherCid => [
                'ID'      => $otherCid,
                'HOST'    => '169.254.169.254',
                'TIME'    => 12,
                'COMMAND' => 'Sleep',
            ],
        ]);
    }

    function test_execute()
    {
        $driver = that(AbstractDriver::create(MYSQL_URL));
        $driver->setup(true);

        /** @var mysqli $connection */
        $connection = $driver->var('connection');

        $url = MYSQL_URL;
        $cid = $connection->thread_id;
        $this->backgroundTask(function () use ($url, $cid) {
            $connection = (fn() => $this->connection)->call(AbstractDriver::create($url));
            while (true) {
                $connection->query("KILL QUERY $cid");
                usleep(500 * 1000);
            }
        });

        $driver->execute('SELECT 1 FROM (SELECT SLEEP(0.2)) as T')->is([[1 => 1]]);
        $driver->execute('SELECT 1 FROM (SELECT SLEEP(1.5)) as T')->wasThrown('Query execution was interrupted');

        $driver->close();
    }
}
