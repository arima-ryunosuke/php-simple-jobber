<?php

namespace ryunosuke\Test\hellowo\Driver;

use mysqli;
use ryunosuke\hellowo\Driver\AbstractDriver;
use ryunosuke\hellowo\Driver\MySqlDriver;
use ryunosuke\hellowo\Exception\DriverException;
use ryunosuke\Test\AbstractTestCase;

class MySqlDriverTest extends AbstractTestCase
{
    use Traits\CancelTrait;
    use Traits\DeadmodeTrait;
    use Traits\LifecycleTrait;
    use Traits\ListTrait;
    use Traits\ShareJobTrait;
    use Traits\SleepTrait;
    use Traits\TransactionTrait;

    const DRIVER_URL = MYSQL_URL;

    protected function setUp(): void
    {
        if (!defined('MYSQL_URL') || !MySqlDriver::isEnabled()) {
            $this->markTestSkipped();
        }
    }

    function test_getConnection()
    {
        $driver = that(new MySqlDriver([
            'transport' => [
                'host' => '0.0.0.0',
                'port' => 9999,
            ],
        ]));
        $driver->getConnection()->wasThrown(/* difference php7/8 */);

        $connection = that(AbstractDriver::create(self::DRIVER_URL))->getConnection()->return();
        $driver     = that(new MySqlDriver([
            'transport' => $connection,
        ]));
        $driver->getConnection()->isSame($connection);
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

    function test_list()
    {
        $this->list();
    }

    function test_transaction()
    {
        $this->transaction();
        $this->select_error();
    }

    function test_isStandby()
    {
        $driver = that(AbstractDriver::create(MYSQL_URL, [
            'waittime' => 2,
            'waitmode' => 'sql',
        ]));
        $driver->setup(true);

        $driver->isStandby()->isFalse();
        $driver->execute('SET SESSION TRANSACTION READ ONLY');
        $driver->isStandby()->isTrue();

        mysqli_report(MYSQLI_REPORT_ERROR);
        set_error_handler(function () { throw new DriverException('', 2006); });
        try {
            $driver->use('isStandby')();
            $this->fail('not thrown mysqli_sql_exception');
        }
        catch (DriverException $e) {
            that($e)->getCode()->is(2006);
        }
        finally {
            restore_error_handler();
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        }
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
        $driver = that(AbstractDriver::create(MYSQL_URL, [
            'waittime' => 2,
            'waitmode' => 'sql',
        ]));
        $driver->setup(true);

        /** @var mysqli $connection */
        $connection = $driver->getConnection()->return();

        $url = MYSQL_URL;
        $cid = $connection->thread_id;
        $this->backgroundTask(function () use ($url, $cid) {
            $connection = (fn() => $this->getConnection())->bindTo(MySqlDriver::create($url), MySqlDriver::class)();
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

    function test_sleep_sql_sigusr1()
    {
        $driver = that(AbstractDriver::create(MYSQL_URL, [
            'waittime' => 2,
            'waitmode' => 'sql',
        ]));
        $driver->setup(true);

        $driver->syscalled = true;
        $driver->connection->query("SELECT SLEEP(1)", MYSQLI_ASYNC);
        $driver->execute('SELECT ? AS c', [1])->is([
            ['c' => 1],
        ]);

        $driver->close();
    }

    function test_recover()
    {
        $tmpdriver = that(AbstractDriver::create(MYSQL_URL, [
            'heartbeat' => 0,
        ]));

        $tmpdriver->recover()->is([]);
        $tmpdriver->processlist()->is([]);

        $otherCid = (int) $tmpdriver->getConnection()->return()->thread_id;

        $driver = that(new class([
            'transport' => that(AbstractDriver::create(MYSQL_URL))->getConnection()->return(),
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
        $connection = $driver->getConnection()->return();

        $url = MYSQL_URL;
        $cid = $connection->thread_id;
        $this->backgroundTask(function () use ($url, $cid) {
            $connection = (fn() => $this->getConnection())->bindTo(MySqlDriver::create($url), MySqlDriver::class)();
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
