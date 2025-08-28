<?php

namespace ryunosuke\Test\hellowo\Driver\Traits;

use ryunosuke\hellowo\Driver\AbstractDriver;

trait SleepTrait
{
    function sleep()
    {
        $driver = that(AbstractDriver::create(self::DRIVER_URL, [
            'waittime' => 1,
            'waitmode' => 'php',
        ]));
        $driver->setup(true);

        $time = microtime(true);
        $driver->sleep();
        that(microtime(true) - $time)->lessThan(1.1);

        $driver->close();
    }
}
