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

        $procdir = sys_get_temp_dir() . '/hellowo/proc';
        foreach (glob("$procdir/*/*") as $file) {
            unlink($file);
        }
        foreach (glob("$procdir/*") as $file) {
            rmdir($file);
        }
    }
}
