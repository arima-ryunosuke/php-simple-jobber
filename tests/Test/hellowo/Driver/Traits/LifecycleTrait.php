<?php

namespace ryunosuke\Test\hellowo\Driver\Traits;

use Exception;
use ryunosuke\hellowo\Driver\AbstractDriver;

trait LifecycleTrait
{
    function lifecycle(?int $retry)
    {
        $driver = that(AbstractDriver::create(self::DRIVER_URL, [
            'waittime' => 1,
            'waitmode' => 'php',
        ]));
        $driver->setup(true);
        $driver->daemonize();

        $driver->clear();
        $driver->send('Z1', 1);
        $driver->send('Z2', 1);
        $driver->notify();
        $driver->clear()->is(2);

        $driver->send('B', 1);
        $driver->send('A', 2);
        $driver->send('X', null, 10);

        $start = time();

        $generator = $driver->select();
        $message   = $generator->current();
        $message->getId()->isNotNull();
        $message->getContents()->is('A');
        $generator->send(null);

        $generator = $driver->select();
        $message   = $generator->current();
        $message->getId()->isNotNull();
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
        $message->isNull();
        sleep(3);
        $generator = $driver->select();
        $message   = $generator->current();
        $message->getContents()->is('C');
        $message->getRetry()->is($retry);
        $generator->send(new Exception('errored'));

        time_sleep_until($start + 11);
        $generator = $driver->select();
        $message   = $generator->current();
        $message->getContents()->is('X');
        $generator->send(null);

        $driver->error(new Exception())->isFalse();

        $driver->close();
    }
}
