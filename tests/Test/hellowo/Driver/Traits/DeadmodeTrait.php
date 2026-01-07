<?php

namespace ryunosuke\Test\hellowo\Driver\Traits;

use Exception;
use ryunosuke\hellowo\Driver\AbstractDriver;

trait DeadmodeTrait
{
    function dead_no()
    {
        $driver = that(AbstractDriver::create(self::DRIVER_URL, [
            'deadmode' => '',
        ]));
        $driver->setup(true);

        $driver->send('C', 1);
        $generator = $driver->select();
        $generator->send(new Exception('errored with table'));
        $driver->execute("SELECT * FROM {$driver->table->return()}")->isEmpty();
    }

    function dead_column()
    {
        $driver = that(AbstractDriver::create(self::DRIVER_URL, [
            'deadmode' => 'column',
        ]));
        $driver->setup(true);

        $driver->send('C', 1);
        $generator = $driver->select();
        $generator->send(new Exception('errored with column'));
        $driver->execute("SELECT * FROM {$driver->table->return()} WHERE error IS NOT NULL")[0]['error']->contains('errored with column');
    }

    function dead_table()
    {
        $driver = that(AbstractDriver::create(self::DRIVER_URL, [
            'deadmode' => 'table',
        ]));
        $driver->setup(true);

        $driver->send('C', 1);
        $generator = $driver->select();
        $generator->send(new Exception('errored with table'));
        $driver->execute("SELECT * FROM {$driver->table->return()}_dead WHERE error IS NOT NULL")[0]['error']->contains('errored with table');
    }
}
