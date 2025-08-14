<?php

namespace ryunosuke\Test\hellowo\Driver;

use Exception;
use ryunosuke\hellowo\Driver\AbstractDriver;
use ryunosuke\hellowo\Driver\PostgreSqlDriver;
use ryunosuke\Test\AbstractTestCase;

class PostgreSqlDriverTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        if (!defined('PGSQL_URL') || !PostgreSqlDriver::isEnabled()) {
            $this->markTestSkipped();
        }
    }

    function test_all()
    {
        $driver = that(AbstractDriver::create(PGSQL_URL, [
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

        $generator = $driver->select();
        $message   = $generator->current();
        $message->getId()->isNumeric();
        $message->getContents()->is('A');
        $generator->send(null);

        $generator = $driver->select();
        $message   = $generator->current();
        $message->getId()->isNumeric();
        $message->getContents()->is('B');
        $generator->send(null);

        $generator = $driver->select();
        $message   = $generator->current();
        $message->isNull();

        $driver->send('C', 1);
        $generator = $driver->select();
        $message   = $generator->current();
        $message->getContents()->is('C');
        $generator->send(2);
        $generator = $driver->select();
        $message   = $generator->current();
        $message->isNull();
        sleep(2);
        $generator = $driver->select();
        $message   = $generator->current();
        $message->getRetry()->is(1);
        $message->getContents()->is('C');
        $generator->send(null);

        $driver->error(new Exception())->isFalse();

        $driver->notify()->isBetween(0, 1); // coverage

        $driver->close();
    }

    function test_transaction()
    {
        $driver = that(AbstractDriver::create(PGSQL_URL));
        $driver->setup(false);
        $driver->clear();

        $original = $driver->table->return();

        $driver->send('A');

        $driver->table = 't_undefined';
        $generator     = $driver->select();
        $generator->current()->wasThrown(" does not exist");

        $driver->table = $original;
        $generator     = $driver->select();
        $generator->current();
        $driver->send('X');
        $driver->execute("SELECT * FROM {$original}")->count(2);
        $driver->table = 't_undefined';
        $generator->send(null)->wasThrown(" does not exist");
        $driver->execute("SELECT * FROM {$original}")->count(1); // rollbacked

        $driver->table = $original;
        $generator     = $driver->select();
        $generator->current();
        $driver->send('X');
        $driver->execute("SELECT * FROM {$original}")->count(2);
        $driver->table = 't_undefined';
        $generator->send(10)->wasThrown(" does not exist");
        $driver->execute("SELECT * FROM {$original}")->count(1); // rollbacked

        $driver->table = $original;
        $generator     = $driver->select();
        $generator->current();
        $driver->send('X');
        $driver->execute("SELECT * FROM {$original}")->count(2);
        $driver->table = 't_undefined';
        $generator->throw(new Exception('throw'))->wasThrown("throw");
        $driver->execute("SELECT * FROM {$original}")->count(1); // rollbacked

        $driver->close();
    }

    function test_isStandby()
    {
        $driver = that(AbstractDriver::create(PGSQL_URL, [
            'waittime' => 2,
            'waitmode' => 'sql',
        ]));
        $driver->setup(true);

        $driver->execute('BEGIN');
        $driver->isStandby()->isFalse();
        $driver->execute('SET TRANSACTION READ ONLY');
        $driver->isStandby()->isTrue();
        $driver->execute('COMMIT');

        pg_close($driver->var('connection'));
        $driver->isStandby()->wasThrown(/* difference php7/8 */);

        restore_error_handler();
    }

    function test_select_sharedFile()
    {
        srand(2);
        $sharedFile = sys_get_temp_dir() . '/jobs.txt';
        @unlink($sharedFile);

        $driver = that(AbstractDriver::create(PGSQL_URL, [
            'waittime'   => 1,
            'waitmode'   => 'php',
            'sharedFile' => $sharedFile,
        ]));
        $driver->setup(true);

        $driver->send('A');
        $driver->send('B');
        $driver->send('C');

        $generator = $driver->select();
        $message   = $generator->current();
        $message->getContents()->is('A');
        $generator->send(null);
        unset($generator);

        $cache = include $sharedFile;
        that($cache['jobs'])->isSame([
            2 => [
                "job_id"   => "2",
                "priority" => "32767",
            ],
            3 => [
                "job_id"   => "3",
                "priority" => "32767",
            ],
        ]);

        $cache['jobs'] = array_replace([-1 => ["id" => -1, "priority" => 32767]], $cache['jobs']);
        file_put_contents($sharedFile, '<?php return ' . var_export($cache, true) . ';');

        $driver->select()->current()->getContents()->isSame('B');

        $driver->close();
    }

    function test_sleep_sql()
    {
        $driver = that(AbstractDriver::create(PGSQL_URL, [
            'waittime' => 2,
            'waitmode' => 'sql',
        ]));
        $driver->setup(true);

        $url = PGSQL_URL;
        $this->backgroundTask(function () use ($url) {
            $connection = (fn() => $this->connection)->bindTo(PostgreSqlDriver::create($url), PostgreSqlDriver::class)();
            sleep(2);
            while (true) {
                pg_query($connection, "NOTIFY hellowo_awake");
                usleep(100 * 1000);
            }
        });

        // stream_select returns 0
        $time = microtime(true);
        $driver->sleep();
        that(microtime(true) - $time)->gt(2);

        // break by hellowo_awake
        $time = microtime(true);
        $driver->sleep();
        that(microtime(true) - $time)->lt(2);

        $driver->close();
    }

    function test_recover()
    {
        $tmpdriver = AbstractDriver::create(PGSQL_URL);

        $driver = that(new class([
            'transport' => that($tmpdriver)->var('connection'),
            'heartbeat' => 0,
        ]) extends PostgreSqlDriver {
            protected function processlist(): array
            {
                parent::processlist(); // for coverage
                return [
                    ['pid' => 1, 'client_addr' => '127.0.0.1', 'state_change' => date('Y-m-d H:i:s', time() - 3600)],
                    ['pid' => 2, 'client_addr' => '127.0.0.2', 'state_change' => date('Y-m-d H:i:s', time() - 3600)],
                ];
            }

            protected function ping(string $host, int $timeout): ?bool
            {
                return $host === '127.0.0.1';
            }
        });

        // no heartbeat
        $driver->recover()->is([]);

        $driver->heartbeat      = 10;
        $driver->heartbeatTimer = microtime(true) + 1;

        // no timer
        $driver->recover()->is([]);

        // pid:2 is killed
        $driver->heartbeatTimer = 0;
        $driver->recover()->is([
            2 => [
                "pid"          => 2,
                "client_addr"  => "127.0.0.2",
                "state_change" => date('Y-m-d H:i:s', time() - 3600),
            ],
        ]);
    }

    function test_execute()
    {
        $driver = that(AbstractDriver::create(PGSQL_URL));
        $driver->setup(true);

        $driver->execute("INSERT INTO testjobs(message) VALUES('test')")->is(1);
        $driver->execute("SELECT * FROM testjobs")->count(1);

        @$driver->execute('SELECT $1')->wasThrown('0 parameters');
    }
}
