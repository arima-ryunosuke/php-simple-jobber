<?php

namespace ryunosuke\Test\hellowo\Driver;

use Exception;
use ryunosuke\hellowo\Driver\AbstractDriver;
use ryunosuke\hellowo\Driver\FileSystemDriver;
use ryunosuke\Test\AbstractTestCase;

class FileSystemDriverTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        if (!defined('FILESYSTEM_URL') || !FileSystemDriver::isEnabled()) {
            $this->markTestSkipped();
        }
    }

    function test_all()
    {
        $driver = that(AbstractDriver::create(FILESYSTEM_URL, [
            'waittime' => 1,
        ]));
        $driver->setup(true);
        $driver->daemonize();

        $driver->clear();
        $driver->send('Z1', 1);
        $driver->send('Z2', 1);
        $driver->clear()->is(2);

        $driver->send('B', 1);
        $driver->send('A', 2);
        $driver->send('X', null, 10);

        $generator = $driver->select();
        $message   = $generator->current();
        $message->getId()->stringEndsWith('.testjob');
        $message->getContents()->is('A');
        $generator->send(null);

        $generator = $driver->select();
        $message   = $generator->current();
        $message->getId()->stringEndsWith('.testjob');
        $message->getContents()->is('B');
        $generator->send(null);

        $generator = $driver->select();
        $message   = $generator->current();
        $message->isNull();

        $driver->send('C');
        $generator = $driver->select();
        $message   = $generator->current();
        $message->getContents()->is('C');
        $generator->send(2);
        $generator = $driver->select();
        $message   = $generator->current();
        $message->isNull();
        sleep(2);
        $generator = $driver->select();
        $message   = $generator->current();
        $message->getContents()->is('C');

        $driver->error(new Exception())->isFalse();

        $driver->close();
    }

    function test_mkdir()
    {
        $directory = $this->emptyDirectory();
        rmdir($directory);

        $driver = that(new FileSystemDriver([
            'waittime'  => 0.01,
            'directory' => $directory,
            'extension' => 'tmp',
        ]));
        $driver->setup();
        $driver->isStandby()->isFalse();
        that($directory)->directoryExists();

        $driver->close();
    }

    function test_expired()
    {
        $directory = $this->emptyDirectory();
        touch($x = "$directory/x.tmp", strtotime('2020/12/23 12:34:56')); // past
        touch($y = "$directory/y.tmp", strtotime('2030/12/23 12:34:56')); // future
        mkdir($z = "$directory/z.tmp");                                   // directory

        $driver = that(new FileSystemDriver([
            'waittime'  => 0.01,
            'directory' => $directory,
            'extension' => 'tmp',
        ]));

        $driver->expired($x, strtotime('2025/12/23 12:34:56'))->isTrue();
        $driver->expired($y, strtotime('2025/12/23 12:34:56'))->isFalse();
        $driver->expired($z, strtotime('2025/12/23 12:34:56'))->isFalse();

        $driver->close();
    }

    function test_isStandby()
    {
        $driver = that(new FileSystemDriver([
            'waittime'  => 0.01,
            'directory' => 'not-found-directory',
            'extension' => 'tmp',
        ]));

        $driver->isStandby()->isTrue();
    }

    function test_sleep_inotify()
    {
        $directory = $this->emptyDirectory();

        $driver = that(new FileSystemDriver([
            'waittime'  => 3,
            'waitmode'  => 'inotify',
            'directory' => $directory,
            'extension' => 'tmp',
        ]));
        $driver->daemonize();

        $this->backgroundTask(function () use ($directory) {
            while (true) {
                touch("$directory/test.tmp");
                usleep(100 * 1000);
            }
        });

        $driver->notify()->is(0);
        $time = microtime(true);
        $driver->sleep();
        that(microtime(true) - $time)->lessThan(3);

        $driver->close();
    }

    function test_recover()
    {
        $directory = $this->emptyDirectory();

        $driver = that(new FileSystemDriver([
            'waittime'  => 0.01,
            'directory' => $directory,
            'extension' => 'tmp',
        ]));
        $driver->setup(true);

        touch("$directory/.working/x.tmp", strtotime('1999/12/23 12:34:56'));
        $driver->recover()->is(["$directory/.working/x.tmp" => "$directory/x.tmp"]);
        that("$directory/x.tmp")->fileExists();

        $driver->close();
    }
}
