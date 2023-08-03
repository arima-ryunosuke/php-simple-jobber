<?php

namespace ryunosuke\Test\hellowo;

use ArrayListener;
use ArrayLogger;
use Closure;
use Exception;
use LogicException;
use Psr\Log\NullLogger;
use RuntimeException;
use ryunosuke\hellowo\Driver\AbstractDriver;
use ryunosuke\hellowo\Exception\RetryableException;
use ryunosuke\hellowo\ext\pcntl;
use ryunosuke\hellowo\ext\posix;
use ryunosuke\hellowo\Message;
use ryunosuke\hellowo\Worker;
use ryunosuke\Test\AbstractTestCase;
use SplFileInfo;

class WorkerTest extends AbstractTestCase
{
    function createDriver($select)
    {
        return new class($select) extends AbstractDriver {
            private Closure $select;

            private int $count = 1;

            public function __construct($select)
            {
                $this->select = $select;
                parent::__construct('');
            }

            public function select(): ?Message
            {
                return ($this->select)($this->count++);
            }

            public function retry(Message $message, float $time): void
            {
                $this->count--;
            }

            public function error(Exception $e): bool
            {
                return $e->getMessage() === 'stop';
            }
        };
    }

    function sleep(int $second)
    {
        declare(ticks=1) {
            for ($i = 0; $i < $second * 100; $i++) {
                usleep(10000);
            }
        }
    }

    function test___construct()
    {
        that(Worker::class)->new([
            'work' => 'hoge',
        ])->wasThrown('work is required');

        that(Worker::class)->new([
            'work' => function () { },
        ])->wasThrown('driver is required');

        that(Worker::class)->new([
            'work'    => function () { },
            'driver'  => $this->createDriver(function () { }),
            'signals' => [
                pcntl::SIGALRM => function () { },
            ],
        ])->wasThrown('SIGALRM is reserved');

        that(Worker::class)->new([
            'work'     => function () { },
            'driver'   => $this->createDriver(function () { }),
            'signals'  => [],
            'listener' => 'hoge',
        ])->wasThrown('listener must be Listener');
    }

    function test_all()
    {
        $stdout = $this->emptyDirectory() . '/stdout.txt';

        $worker = new Worker([
            'work'     => function (Message $message) use ($stdout) {
                // fail
                if ($message->getContents() === "2") {
                    throw new Exception();
                }
                // timeout
                if ($message->getContents() === "3") {
                    $this->sleep(5);
                }
                // retry
                if ($message->getContents() === "4") {
                    static $retry_count = 0;
                    if ($retry_count++ < 3) {
                        throw new RetryableException(0.1);
                    }
                }
                file_put_contents($stdout, $message, FILE_APPEND | LOCK_EX);
            },
            'driver'   => $this->createDriver(function ($count) {
                // through
                if ($count === 1) {
                    return null;
                }
                // endloop
                if ($count === 6) {
                    throw new LogicException('stop');
                }
                return new Message(null, $count, $count);
            }),
            'logger'   => new ArrayLogger($logs),
            'listener' => new ArrayListener($events),
            'signals'  => [],
            'timeout'  => 1,
        ]);

        $worker->start();

        // 1:through, 2:fail, 3:timeout, 4:fail but retry
        that($stdout)->fileEquals("45");

        that($logs)->matchesCountEquals([
            '#^start:#'   => 1,
            '#^begin:#'   => 1,
            '#^job:#'     => null,
            '#^done:#'    => null,
            '#^fail:#'    => 1,
            '#^timeout:#' => 1,
            '#^retry:#'   => 3,
            '#^end:#'     => 1,
        ]);

        that($events)->is([
            "fail"    => ["2"],
            "timeout" => ["3"],
            "retry"   => ["4", "4", "4"],
            "done"    => ["4", "5"],
            "cycle"   => [0, 1, 2, 3, 4, 5, 6, 7],
        ]);
    }

    function test_signal()
    {
        $logs   = [];
        $worker = new Worker([
            'work'    => function (Message $message) {
                if ($message->getContents() === "2") {
                    posix::kill(getmypid(), pcntl::SIGTERM);
                    $this->sleep(5);
                }
            },
            'driver'  => $this->createDriver(function ($count) {
                if ($count === 1) {
                    posix::kill(getmypid(), pcntl::SIGUSR2);
                }
                if ($count === 2) {
                    return new Message(null, $count, $count);
                }
                return null;
            }),
            'logger'  => new NullLogger(),
            'signals' => [
                pcntl::SIGUSR2 => function ($signal) use (&$logs) {
                    $logs[] = $signal;
                },
                pcntl::SIGTERM => null,
            ],
        ]);

        $worker->start();

        that($logs)->contains(pcntl::SIGUSR2);
        that($logs)->notContains(pcntl::SIGTERM);
    }

    function test_error()
    {
        $worker = new Worker([
            'work'    => function () { throw new Exception(); },
            'driver'  => $this->createDriver(function () use (&$worker) {
                static $count = 0;
                $count++;
                // error
                if ($count === 1) {
                    throw new RuntimeException();
                }
                // endloop
                if ($count === 3) {
                    posix::kill(getmypid(), pcntl::SIGTERM);
                }
                return new Message(null, $count, $count);
            }),
            'logger'  => new ArrayLogger($logs),
            'signals' => [],
        ]);

        $worker->start();

        that($logs)->matchesCountEquals([
            '#^start:#' => 1,
            '#^begin:#' => 1,
            '#^job:#'   => null,
            '#^error:#' => 1,
            '#^end:#'   => 1,
        ]);
    }

    function test_log()
    {
        $worker = that(new Worker([
            'work'    => function () { },
            'driver'  => $this->createDriver(function () { return null; }),
            'signals' => [],
        ]));

        $worker->logString([1, new SplFileInfo('file'), new RuntimeException('msg', 3)])
            ->stringStartsWith('[1,"file","caught RuntimeException(3, msg) in');
    }
}
