<?php

namespace ryunosuke\Test\hellowo\Driver\Traits;

use ryunosuke\hellowo\Driver\AbstractDriver;

trait CancelTrait
{
    function cancel()
    {
        $driver = that(AbstractDriver::create(self::DRIVER_URL));
        $driver->setup(true);

        $c1 = $driver->send('C1');
        $c2 = $driver->send('C2');
        $c3 = $driver->send('C3');

        $driver->cancel(-1)->is(0);
        $driver->cancel(-1, 'notfound')->is(0);
        $driver->cancel($c1)->is(1);
        $driver->cancel($c2, 'notfound')->is(1);
        $driver->cancel(-1, 'C3')->is(1);
        $driver->cancel($c3)->is(0);
    }
}
