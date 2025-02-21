<?php

namespace ryunosuke\Test\hellowo;

use ArrayObject;
use JsonSerializable;
use RuntimeException;
use ryunosuke\hellowo\API;
use ryunosuke\hellowo\ext\pcntl;
use ryunosuke\Test\AbstractTestCase;
use SplFileInfo;

class APITest extends AbstractTestCase
{
    function createAPI()
    {
        return new class ( ) extends API { };
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

        that(API::class)::notifyLocal(1)->is([1234]);

        that("$processdir/1234/signal")->fileEquals(pcntl::SIGUSR1 . "\n");
        that("$processdir/9999/signal")->fileNotExists();

        that(API::class)::notifyLocal(99)->is([1234, 9999]);

        that("$processdir/1234/signal")->fileEquals(pcntl::SIGUSR1 . "\n" . pcntl::SIGUSR1 . "\n");
        that("$processdir/9999/signal")->fileEquals(pcntl::SIGUSR1 . "\n");
    }

    function test_log()
    {
        $api = that($this->createAPI());

        $api->logString([null, 1, 'hoge', STDOUT, [1, 2, 3]])->is('[null,1,"hoge","Resource id #2",[1,2,3]]');
        $api->logString((object) ['hoge' => 'HOGE'])->is('{"hoge":"HOGE"}');
        $api->logString(new SplFileInfo('file'))->is('file');
        $api->logString(new RuntimeException('msg', 3))->stringStartsWith('caught RuntimeException(3, msg) in');
        $api->logString(new ArrayObject(['a', 'b']))->is('ArrayObject{"0":"a","1":"b"}');
        $api->logString(new class() implements JsonSerializable {
            #[\ReturnTypeWillChange]
            public function jsonSerialize() { return [1]; }
        })->is('[1]');
    }
}
