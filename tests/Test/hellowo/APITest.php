<?php

namespace ryunosuke\Test\hellowo;

use ArrayObject;
use JsonSerializable;
use ryunosuke\hellowo\API;
use ryunosuke\hellowo\ext\pcntl;
use ryunosuke\Test\AbstractTestCase;
use SplFileInfo;
use Stringable;

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

        $api       = that($this->createAPI());
        $api->name = 'hellowo';

        $GLOBALS['hellowo-processes'][1234]['cmdline'] = '#hellowo';
        $GLOBALS['hellowo-processes'][9999]['cmdline'] = '#hellowo';

        $api->notifyLocal(1)->is([1234]);

        that($GLOBALS['hellowo-processes'][1234]['signal'])->is([pcntl::SIGUSR1]);
        @that($GLOBALS['hellowo-processes'][9999]['signal'])->is(null);

        $api->notifyLocal(99)->is([1234, 9999]);

        that($GLOBALS['hellowo-processes'][1234]['signal'])->is([pcntl::SIGUSR1, pcntl::SIGUSR1]);
        that($GLOBALS['hellowo-processes'][9999]['signal'])->is([pcntl::SIGUSR1]);
    }

    function test_messageString()
    {
        $api = that($this->createAPI());

        // scalar|array
        $api->messageString(false)->isSame('');
        $api->messageString(123)->isSame('123');
        $api->messageString('string')->isSame('string');
        $api->messageString([1, 2, 3])->isSame('[1,2,3]');

        // object(stdClass)
        $api->messageString((object) ['std' => 'Class'])->isSame('{"std":"Class"}');

        // object(JsonSerializable)
        $api->messageString(new class() implements JsonSerializable {
            #[\ReturnTypeWillChange]
            public function jsonSerialize()
            {
                return ['json' => 'Serialize'];
            }
        })->isSame('{"json":"Serialize"}');

        // object(Stringable)
        $api->messageString(new class() implements Stringable {
            public function __toString(): string
            {
                return 'stringable';
            }
        })->isSame('stringable');

        // object(JsonSerializable&Stringable)
        $api->messageString(new class() implements JsonSerializable, Stringable {
            #[\ReturnTypeWillChange]
            public function jsonSerialize()
            {
                return ['json' => 'Serialize'];
            }

            public function __toString(): string
            {
                return 'stringable';
            }
        })->isSame('{"json":"Serialize"}');
    }

    function test_log()
    {
        $api = that($this->createAPI());

        $api->logString(null)->is('null');
        $api->logString(false)->is('false');
        $api->logString([null, 1, 'hoge', STDOUT, [1, 2, 3]])->is('[null,1,"hoge","Resource id #2",[1,2,3]]');
        $api->logString((object) ['hoge' => 'HOGE'])->is('{"hoge":"HOGE"}');
        $api->logString(new SplFileInfo('file'))->is('file');
        $api->logString(new ArrayObject(['a', 'b']))->is('ArrayObject{"0":"a","1":"b"}');
        $api->logString(new class() implements JsonSerializable {
            #[\ReturnTypeWillChange]
            public function jsonSerialize()
            {
                return [1];
            }
        })->is('[1]');
    }
}
