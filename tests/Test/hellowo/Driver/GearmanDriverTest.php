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

        $generator = $driver->select();
        $message   = $generator->current();
        $message->getId()->stringLengthEquals(36);
        $message->getContents()->is('A');
        $generator->send(null);

        $generator = $driver->select();
        $message   = $generator->current();
        $message->getId()->stringLengthEquals(36);
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
        $message->getContents()->is('C');

        $driver->error(new Exception())->isFalse();

        $driver->close();
    }
}
