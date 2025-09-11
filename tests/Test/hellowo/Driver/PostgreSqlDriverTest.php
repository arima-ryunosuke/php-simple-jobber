<?php

namespace ryunosuke\Test\hellowo\Driver;

use ryunosuke\hellowo\Driver\AbstractDriver;
use ryunosuke\hellowo\Driver\PostgreSqlDriver;
use ryunosuke\Test\AbstractTestCase;

class PostgreSqlDriverTest extends AbstractTestCase
{
    use Traits\CancelTrait;
    use Traits\DeadmodeTrait;
    use Traits\LifecycleTrait;
    use Traits\ShareJobTrait;
    use Traits\SleepTrait;
    use Traits\TransactionTrait;

    const DRIVER_URL = PGSQL_URL;

    protected function setUp(): void
    {
        if (!defined('PGSQL_URL') || !PostgreSqlDriver::isEnabled()) {
            $this->markTestSkipped();
        }
    }

    function test_lifecycle()
    {
        $this->lifecycle(1);

        $driver = that(AbstractDriver::create(self::DRIVER_URL));
        $driver->execute("SELECT * FROM {$driver->table->return()} WHERE error IS NOT NULL")->count(0);
    }

    function test_dead()
    {
        $this->dead_column();
        $this->dead_table();
    }

    function test_transaction()
    {
        $this->transaction();
        $this->select_error();
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

    function test_shareJob()
    {
        $this->shareJob();
    }

    function test_cancel()
    {
        $this->cancel();

        $driver        = that(AbstractDriver::create(self::DRIVER_URL));
        $driver->table = 't_undefined';
        $driver->cancel(-1)->wasThrown(" exist");
    }

    function test_sleep_php()
    {
        $this->sleep();
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
        $driver->notify();
        that(microtime(true) - $time)->gt(2);

        // break by hellowo_awake
        $time = microtime(true);
        $driver->sleep();
        $driver->notify();
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

        $driver->execute("INSERT INTO testjobs(job_data) VALUES('1')")->is(1);
        $driver->execute("SELECT * FROM testjobs")->count(1);

        @$driver->execute('INVALID')->wasThrown('near "INVALID"');
        @$driver->execute('SELECT $1')->wasThrown('0 parameters');
    }
}
