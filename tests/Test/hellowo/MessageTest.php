<?php

namespace ryunosuke\Test\hellowo;

use ryunosuke\hellowo\Message;
use ryunosuke\Test\AbstractTestCase;

class MessageTest extends AbstractTestCase
{
    function test_all()
    {
        $message = that(new Message(123, '{"t": 1234567890}', 123, 456));

        $message->getId()->isSame('123');
        $message->getContents()->isSame('{"t": 1234567890}');
        $message->getJsonContents()->isSame(['t' => 1234567890]);
        $message->getJsonContents(0)->is((object) ['t' => 1234567890]);
        $message->getRetry()->isSame(123);
        $message->getTimeout()->isSame(456);
    }
}
