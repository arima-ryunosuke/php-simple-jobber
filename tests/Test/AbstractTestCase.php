<?php

namespace ryunosuke\Test;

use PHPUnit\Framework\TestCase;
use ryunosuke\PHPUnit\TestCaseTrait;

class AbstractTestCase extends TestCase
{
    use TestCaseTrait;

    protected function setUp(): void
    {
        parent::setUp();
    }
}
