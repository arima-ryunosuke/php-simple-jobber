<?php

namespace ryunosuke\Test\hellowo\Driver;

use ryunosuke\hellowo\Driver\AbstractDriver;
use ryunosuke\hellowo\Driver\GearmanDriver;
use ryunosuke\Test\AbstractTestCase;

class GearmanDriverTest extends AbstractTestCase
{
    use Traits\LifecycleTrait;

    const DRIVER_URL = GEARMAN_URL;

    protected function setUp(): void
    {
        if (!defined('GEARMAN_URL') || !GearmanDriver::isEnabled()) {
            $this->markTestSkipped();
        }
    }

    function test_lifecycle()
    {
        $this->lifecycle(1, false);
    }

    function test_close()
    {
        $driver = that(AbstractDriver::create(GEARMAN_URL, [
            'waittime' => 1.0,
        ]));
        $driver->setup(true);
        $driver->daemonize();
        $driver->send('X');
        $driver->select()->current()->getContents()->is('X');
        $driver->close();

        $driver = that(AbstractDriver::create(GEARMAN_URL, [
            'waittime' => 1.0,
        ]));
        $driver->daemonize();
        $driver->select()->current()->getContents()->is('X');
    }
}
