<?php

namespace ryunosuke\Test\hellowo\Driver;

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

    function test_lifecycle()
    {
        $this->lifecycle(0, true);
    }
}
