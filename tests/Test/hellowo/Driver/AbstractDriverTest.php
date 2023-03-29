<?php

namespace ryunosuke\Test\hellowo\Driver;

use Exception;
use RuntimeException;
use ryunosuke\hellowo\Driver\AbstractDriver;
use ryunosuke\Test\AbstractTestCase;

class AbstractDriverTest extends AbstractTestCase
{
    function test_all()
    {
        $driver = that(new class ( "abstract" ) extends AbstractDriver { });

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
        $procdir = sys_get_temp_dir() . '/hellowo/proc';
        @mkdir("$procdir/999", 0777, true);

        $driver = that(new class ( "" ) extends AbstractDriver { });

        $driver->normalizeParams([])->is([]);

        $driver->notify()->is(0);

        if (DIRECTORY_SEPARATOR === '\\') {
            file_put_contents("$procdir/999/cmdline", '#hellowo');
            $driver->notify(true)->is(1);
        }
    }
}
