<?php

namespace ryunosuke\Test\hellowo\Driver;

use DateTime;
use Exception;
use RuntimeException;
use ryunosuke\hellowo\Driver\AbstractDriver;
use ryunosuke\Test\AbstractTestCase;

class AbstractDriverTest extends AbstractTestCase
{
    function createDriver()
    {
        return new class ( "abstract", null ) extends AbstractDriver { };
    }

    function test_all()
    {
        $driver = that($this->createDriver());

        $driver::isEnabled()->isTrue();

        $driver->stringStartsWith("abstract");

        $driver->ping('127.0.0.1', 1)->isTrue();
        $driver->ping('169.254.169.254', 1)->isFalse();
        $driver->ping('1invalidhostname', 1)->isNull();

        $actual = $driver->normalizeArguments([$this, 'namedMethod'], [
            'dummy' => null,
            'arg2'  => 21,
            0       => '11',
        ]);
        $actual[0]->isSame('11');
        $actual[1]->isSame(21);
        $actual[2]->isSame(3);

        $driver->assertArguments([
            'null'      => 'dummy',
            'bool'      => false,
            'int'       => '8080',
            'float'     => "3.14",
            'string'    => 'fuga',
            'resource'  => STDERR,
            'calllable' => fn() => null,
            'object'    => new RuntimeException(),
            'array'     => [
                'list'   => [4, 5, 6],
                'hash'   => ['a' => 'A'],
                'object' => (object) ['a' => 'A'],
            ],
        ], [
            'null'      => null,
            'bool'      => true,
            'int'       => 8080,
            'float'     => 3.14,
            'string'    => 'hoge',
            'resource'  => STDOUT,
            'calllable' => fn() => null,
            'object'    => new Exception(),
            'array'     => [
                'list'   => [1, 2, 3],
                'hash'   => ['a' => 'A'],
                'object' => ['a' => 'A'],
            ],
        ])->is([
            "/null"      => true,
            "/bool"      => true,
            "/int"       => true,
            "/float"     => true,
            "/string"    => true,
            "/resource"  => true,
            "/calllable" => true,
            "/object"    => true,
            "/array"     => [
                "/array/list"   => true,
                "/array/hash"   => [
                    "/array/hash/a" => true,
                ],
                "/array/object" => true,
            ],
        ]);

        $driver->assertArguments([
            'null'      => 'dummy',
            'bool'      => '1',
            'int'       => '3.14',
            'float'     => "hoge",
            'string'    => '',
            'resource'  => 1,
            'calllable' => 'dummy-function',
            'object'    => (object) [],
            'array'     => [
                'list'   => ['a' => 'A'],
                'hash'   => ['x' => 'X'],
                'object' => (object) ['x' => 'X'],
            ],
        ], [
            'null'      => null,
            'bool'      => true,
            'int'       => 8080,
            'float'     => 3.14,
            'string'    => 'hoge',
            'resource'  => STDOUT,
            'calllable' => fn() => null,
            'object'    => new Exception(),
            'array'     => [
                'list'   => [1, 2, 3],
                'hash'   => ['a' => 'A'],
                'object' => ['a' => 'A'],
            ],
        ])->is([
            "/null"      => true,
            "/bool"      => false,
            "/int"       => false,
            "/float"     => false,
            "/string"    => false,
            "/resource"  => false,
            "/calllable" => false,
            "/object"    => false,
            "/array"     => [
                "/array/list"   => false,
                "/array/hash"   => [
                    "/array/hash/a" => false,
                ],
                "/array/object" => true,
            ],
        ]);
    }

    function namedMethod($arg1, $arg2 = 2, $arg3 = 3)
    {
        return compact('arg1', 'arg2', 'arg3');
    }

    function test_misc()
    {
        $driver       = that(new class ("", null) extends AbstractDriver {
            protected function notify(int $count = 1): int
            {
                return count($this->notifyLocal($count));
            }
        });
        $driver->name = 'hellowo';

        $driver->normalizeParams([])->is([]);

        $driver->notify()->is(0);

        if (DIRECTORY_SEPARATOR === '\\') {
            $GLOBALS['hellowo-processes'][999]['cmdline'] = '#hellowo';
            $driver->notify(true)->is(1);
        }
    }

    function test_code()
    {
        $driver = that($this->createDriver());

        $driver->encode(['hoge' => 'HOGE'])->isJson();
        $driver->decode('{"hoge":"HOGE"}')->isArray();

        $driver->encode(['invalid' => NAN])->wasThrown('failed to encode');
        $driver->decode('invalid')->wasThrown('failed to decode');
    }

    function test_getDelay()
    {
        $driver = that($this->createDriver());

        $driver->getDelay(null)->is('0.0');
        $driver->getDelay(0.5)->is('0.5');
        $driver->getDelay(1.5)->is('1.5');
        $driver->getDelay("1.5")->is('1.5');
        $driver->getDelay((new DateTime('+3 seconds'))->format('Y/m/d H:i:s'))->isBetween(2, 3);
        $driver->getDelay('+1 day')->is("86400", 0.1);
    }

    function test_shareJob()
    {
        $jobFilename = sys_get_temp_dir() . '/jobs.txt';
        @unlink($jobFilename);

        $driver = that($this->createDriver());

        $driver->shareJob($jobFilename, 2, 1, fn() => [1 => ['id' => 1]], 123)->isSame([1 => ['id' => 1]]); // first
        $driver->shareJob($jobFilename, 2, 1, fn() => [2 => ['id' => 2]], 124)->isSame([1 => ['id' => 1]]); // within expiration
        $driver->shareJob($jobFilename, 2, 1, fn() => [3 => ['id' => 3]], 125)->isSame([3 => ['id' => 3]]); // expired
        $driver->unshareJob($jobFilename, 3)->isSame(['id' => 3]);
        $driver->shareJob($jobFilename, 2, 1, fn() => [4 => ['id' => 4]], 125)->isSame([4 => ['id' => 4]]); // reselect

        $driver->shareJob($jobFilename, 2, 2, fn() => [1 => ['id' => 1], 2 => ['id' => 2]], 127)->isSame([1 => ['id' => 1], 2 => ['id' => 2]]);
        $driver->unshareJob($jobFilename, 1)->isSame(['id' => 1]);
        $driver->shareJob($jobFilename, 2, 1, fn() => [3 => ['id' => 3]], 127)->isSame([2 => ['id' => 2]]); // not return id:1

        $driver->shareJob($jobFilename, 2, 2, fn() => [1 => ['id' => 1, 'priority' => 1], 2 => ['id' => 2, 'priority' => 2]], 130)->isSame([
            1 => [
                "id"       => 1,
                "priority" => 1,
            ],
            2 => [
                "id"       => 2,
                "priority" => 2,
            ],
        ]);
        $driver->shareJob($jobFilename, 2, 2, fn() => [], 131)->isSame([
            2 => [
                "id"       => 2,
                "priority" => 2,
            ],
            1 => [
                "id"       => 1,
                "priority" => 1,
            ],
        ]);
    }

    function test_waitTime()
    {
        $driver = that($this->createDriver());

        $driver->waitTime(null, 7.89)->isSame(7.89);
        $driver->waitTime(strtotime('2014-12-24 00:00:00') + .123, 7.89)->lt(7.89);

        $driver->waitTime(strtotime('2014-12-24 00:00:00'), 5.0, strtotime('2014-12-24 12:00:00'))->closesTo(0.0);
        $driver->waitTime(strtotime('2014-12-24 00:00:00'), 5.0, strtotime('2014-12-24 12:00:01'))->closesTo(4.0);
        $driver->waitTime(strtotime('2014-12-24 00:00:00'), 5.0, strtotime('2014-12-24 12:00:02'))->closesTo(3.0);
        $driver->waitTime(strtotime('2014-12-24 00:00:00'), 5.0, strtotime('2014-12-24 12:00:03'))->closesTo(2.0);
        $driver->waitTime(strtotime('2014-12-24 00:00:00'), 5.0, strtotime('2014-12-24 12:00:04'))->closesTo(1.0);
        $driver->waitTime(strtotime('2014-12-24 00:00:00'), 5.0, strtotime('2014-12-24 12:00:05'))->closesTo(0.0);
    }

    function test_cancel()
    {
        $driver = that($this->createDriver());

        $driver->cancel(-1)->wasThrown("is not supported");
    }

    function test_list()
    {
        $driver = that($this->createDriver());

        $driver->try('list')->is([]);
    }
}
