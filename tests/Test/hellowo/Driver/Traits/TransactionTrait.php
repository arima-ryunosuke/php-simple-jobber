<?php

namespace ryunosuke\Test\hellowo\Driver\Traits;

use Exception;
use ryunosuke\hellowo\Driver\AbstractDriver;
use ryunosuke\hellowo\Exception\DriverException;

trait TransactionTrait
{
    function transaction()
    {
        $driver = that(AbstractDriver::create(self::DRIVER_URL));
        $driver->setup(false);
        $driver->clear();

        $original = $driver->table->return();

        $driver->send('A');

        $driver->table = 't_undefined';
        $generator     = $driver->select();
        $generator->current()->wasThrown(" exist");

        $driver->table = $original;
        $generator     = $driver->select();
        $generator->current();
        $driver->send('X');
        $driver->execute("SELECT * FROM {$original}")->count(2);
        $driver->table = 't_undefined';
        $generator->send(null)->wasThrown(" exist");
        $driver->execute("SELECT * FROM {$original}")->count(1); // rollbacked

        $driver->table = $original;
        $generator     = $driver->select();
        $generator->current();
        $driver->send('X');
        $driver->execute("SELECT * FROM {$original}")->count(2);
        $driver->table = 't_undefined';
        $generator->send(10)->wasThrown(" exist");
        $driver->execute("SELECT * FROM {$original}")->count(1); // rollbacked

        $driver->table = $original;
        $generator     = $driver->select();
        $generator->current();
        $driver->send('X');
        $driver->execute("SELECT * FROM {$original}")->count(2);
        $driver->table = 't_undefined';
        $generator->throw(new Exception('throw'))->wasThrown("throw");
        $driver->execute("SELECT * FROM {$original}")->count(1); // rollbacked

        $driver->close();
    }

    function select_error()
    {
        $driver = that(AbstractDriver::create(self::DRIVER_URL));
        $driver->setup(true);

        $driver->query('START TRANSACTION READ ONLY');
        try {
            $job = $driver->select()->current();
            $job->isInstanceOf(DriverException::class);
            that($driver->error($job->return()));
        }
        finally {
            $driver->query('ROLLBACK');
        }
    }
}
