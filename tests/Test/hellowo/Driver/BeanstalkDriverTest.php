<?php

namespace ryunosuke\Test\hellowo\Driver;

use ryunosuke\hellowo\Driver\AbstractDriver;
use ryunosuke\hellowo\Driver\BeanstalkDriver;
use ryunosuke\Test\AbstractTestCase;

class BeanstalkDriverTest extends AbstractTestCase
{
    use Traits\LifecycleTrait;

    const DRIVER_URL = BEANSTALK_URL;

    protected function setUp(): void
    {
        if (!defined('BEANSTALK_URL') || !BeanstalkDriver::isEnabled()) {
            $this->markTestSkipped();
        }
    }

    function test_getConnection()
    {
        $driver = that(new BeanstalkDriver([
            'transport' => [
                'host' => '0.0.0.0',
                'port' => 9999,
            ],
        ]));
        $driver->getConnection()->wasThrown(/* difference php7/8 */);

        $connection = that(AbstractDriver::create(self::DRIVER_URL))->getConnection()->return();
        $driver     = that(new BeanstalkDriver([
            'transport' => $connection,
        ]));
        $driver->getConnection()->isSame($connection);
    }

    function test_lifecycle()
    {
        $this->lifecycle(0);
    }
}
