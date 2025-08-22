<?php

namespace ryunosuke\Test\hellowo\Driver\Traits;

use ryunosuke\hellowo\Driver\AbstractDriver;

trait ShareJobTrait
{
    function shareJob()
    {
        $sharedFile = sys_get_temp_dir() . '/jobs.txt';
        @unlink($sharedFile);

        $driver = that(AbstractDriver::create(self::DRIVER_URL, [
            'waittime'   => 1,
            'waitmode'   => 'php',
            'sharedFile' => $sharedFile,
        ]));
        $driver->setup(true);

        $driver->send('A');
        $driver->send('B');
        $driver->send('C');

        $generator = $driver->select();
        $message   = $generator->current();
        $message->getContents()->is('A');
        $generator->send(null);
        unset($generator);

        $cache = json_decode(file_get_contents($sharedFile), true);
        that($cache['jobs'])->is([
            2 => [
                "job_id"   => 2,
                "priority" => 32767,
            ],
            3 => [
                "job_id"   => 3,
                "priority" => 32767,
            ],
        ]);

        $cache['jobs'] = array_replace([-1 => ["id" => -1, "priority" => 65535]], $cache['jobs']);
        file_put_contents($sharedFile, json_encode($cache));

        $driver->select()->current()->getContents()->isSameAny(['B', 'C']);

        $driver->close();
    }
}
