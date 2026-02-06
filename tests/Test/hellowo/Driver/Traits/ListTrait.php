<?php

namespace ryunosuke\Test\hellowo\Driver\Traits;

use ryunosuke\hellowo\Driver\AbstractDriver;

trait ListTrait
{
    function list()
    {
        $driver = that(AbstractDriver::create(self::DRIVER_URL));
        $driver->setup(true);

        $driver->try('list')->count(0);

        $driver->send('C1');
        $driver->send('C2');
        $driver->send('C3');

        $driver->try('list')->count(3);

        $generator = $driver->select();
        $generator->send(null);

        $driver->try('list')->count(2);
    }
}
