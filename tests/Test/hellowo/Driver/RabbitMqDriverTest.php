<?php

namespace ryunosuke\Test\hellowo\Driver;

use Exception;
use ryunosuke\hellowo\Driver\AbstractDriver;
use ryunosuke\hellowo\Driver\RabbitMqDriver;
use ryunosuke\Test\AbstractTestCase;

class RabbitMqDriverTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        if (!defined('RABBITMQ_URL') || !RabbitMqDriver::isEnabled()) {
            $this->markTestSkipped();
        }
    }

    function test_all()
    {
        $driver = that(AbstractDriver::create(RABBITMQ_URL, [
            'waittime' => 1,
        ]));
        $driver->setup(true);
        $driver->daemonize();

        $driver->clear();
        $driver->send('Z1', 1);
        $driver->send('Z2', 1);
        $driver->clear()->is(2);

        $driver->send('A', 2); // for some reason, priorities aren't working
        $driver->send('B', 1);

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
        $message->getRetry()->is(0);
        $message->getContents()->is('C');
        $generator->send(null);

        $driver->error(new Exception())->isFalse();

        $driver->close();
    }
}
