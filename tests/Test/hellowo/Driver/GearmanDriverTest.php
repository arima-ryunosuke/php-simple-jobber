<?php

namespace ryunosuke\Test\hellowo\Driver;

use Exception;
use ryunosuke\hellowo\Driver\AbstractDriver;
use ryunosuke\hellowo\Driver\GearmanDriver;
use ryunosuke\Test\AbstractTestCase;

class GearmanDriverTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        if (!defined('GEARMAN_URL') || !GearmanDriver::isEnabled()) {
            $this->markTestSkipped();
        }
    }

    function test_all()
    {
        $driver = that(AbstractDriver::create(GEARMAN_URL, [
            'waittime' => 1,
        ]));
        $driver->setup(true);
        $driver->daemonize();

        $driver->clear();
        $driver->send('Z1', 1);
        $driver->send('Z2', 1);
        $driver->clear()->is(2);

        $driver->send('B', 1);
        $driver->send('A', 2);

        $message = $driver->select();
        $message->getOriginal()->isObject();
        $message->getId()->stringLengthEquals(36);
        $message->getContents()->is('A');
        $driver->done($message);

        $message = $driver->select();
        $message->getOriginal()->isObject();
        $message->getId()->stringLengthEquals(36);
        $message->getContents()->is('B');
        $driver->done($message);

        $driver->select()->isNull();

        $driver->send('C', 1);
        $message = $driver->select();
        $message->getContents()->is('C');
        $driver->retry($message, 2);
        $message = $driver->select();
        $message->getContents()->is('C');

        $driver->error(new Exception())->isFalse();

        $driver->close();
    }
}
