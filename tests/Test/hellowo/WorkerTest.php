<?php

namespace ryunosuke\Test\hellowo;

use ArrayListener;
use ArrayLogger;
use Closure;
use Error;
use Exception;
use Generator;
use LogicException;
use Psr\Log\NullLogger;
use RuntimeException;
use ryunosuke\hellowo\Driver\AbstractDriver;
use ryunosuke\hellowo\Exception\ExitException;
use ryunosuke\hellowo\Exception\RetryableException;
use ryunosuke\hellowo\ext\pcntl;
use ryunosuke\hellowo\ext\posix;
use ryunosuke\hellowo\Message;
use ryunosuke\hellowo\Worker;
use ryunosuke\Test\AbstractTestCase;
use Throwable;

class WorkerTest extends AbstractTestCase
{
    function createDriver($select, $setup = null, $standby = null)
    {
        return new class($select, $setup, $standby) extends AbstractDriver {
            private Closure $select;
            private Closure $setup;
            private Closure $standby;

            private int $count = 1;

            public function __construct($select, $setup, $standby)
            {
                $this->select  = $select;
                $this->standby = $standby ?? fn() => false;
                $this->setup   = $setup ?? fn() => null;
                parent::__construct('');
            }

            public function setup(bool $forcibly = false): void
            {
                ($this->setup)();
            }

            public function isStandby(): bool
            {
                return ($this->standby)();
            }

            public function select(): Generator
            {
                try {
                    $message = ($this->select)($this->count++);
                    $result  = yield $message;
                    if ($result === null) {
                        // done
                    }
                    elseif (is_int($result) || is_float($result)) {
                        // retry
                        $this->count--;
                    }
                    else {
                        // fail
                    }
                }
                catch (Throwable $t) {
                    throw $t;
                }
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
            'driver'   => $this->createDriver(function ($count) {
                // through
                if ($count === 1) {
                    return null;
                }
                // endloop
                if ($count === 6) {
                    throw new LogicException('stop');
                }
                return new Message($count, $count, 0, 0);
            }),
            'logger'   => new ArrayLogger($logs),
            'listener' => new ArrayListener($events),
            'signals'  => [],
            'timeout'  => 1,
        ]);

        try {
            $worker->start(function (Message $message) use ($stdout) {
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
            });
        }
        catch (Throwable $t) {
            that($t)->getMessage()->is('stop');
        }

        // 1:through, 2:fail, 3:timeout, 4:fail but retry
        that($stdout)->fileEquals("45");

        that($logs)->matchesCountEquals([
            '#^\\[\\d+\\]breather:#' => 1,
            '#^\\[\\d+\\]start:#'    => 1,
            '#^\\[\\d+\\]begin:#'    => 1,
            '#^\\[\\d+\\]job:#'      => null,
            '#^\\[\\d+\\]done:#'     => null,
            '#^\\[\\d+\\]fail:#'     => 1,
            '#^\\[\\d+\\]timeout:#'  => 1,
            '#^\\[\\d+\\]retry:#'    => 3,
            '#^\\[\\d+\\]finish:#'   => null,
            '#^\\[\\d+\\]end:#'      => 0,
        ]);

        that($events)->isSame([
            "breather" => [0],
            "cycle"    => [0, 1, 2, 3, 4, 5, 6, 7],
            "fail"     => ["2"],
            "finish"   => ["2", "3", "4", "4", "4", "4", "5"],
            "timeout"  => ["3"],
            "retry"    => ["4", "4", "4"],
            "done"     => ["4", "5"],
        ]);
    }

    function test_setup()
    {
        $stdout = $this->emptyDirectory() . '/stdout.txt';

        $worker = new Worker([
            'driver'   => $this->createDriver(function ($count) {
                // endloop
                if ($count === 5) {
                    throw new LogicException('stop');
                }
                return new Message($count, $count, 0, 0);
            }, function () {
                throw new RuntimeException('setup failed');
            }),
            'logger'   => new ArrayLogger($logs),
            'listener' => new ArrayListener($events),
            'signals'  => [],
            'timeout'  => 1,
        ]);

        try {
            $worker->start(function (Message $message) use ($stdout) {
                file_put_contents($stdout, $message, FILE_APPEND | LOCK_EX);
            });
        }
        catch (Throwable $t) {
            that($t)->getMessage()->is('stop');
        }

        that($logs)->matchesCountEquals([
            '#^\\[\\d+\\]setup: caught#' => 1,
        ]);
        that($stdout)->fileEquals("1234");
    }

    function test_standby()
    {
        $worker = that(new Worker([
            'driver'   => $this->createDriver(function ($count) {
                return new Message($count, $count, 0, 0);
            }, null, function () {
                static $counter = 0;
                return $counter++ < 3;
            }),
            'logger'   => new ArrayLogger($logs),
            'listener' => new ArrayListener($events),
            'signals'  => [],
            'timeout'  => 1,
        ]));

        $worker->start(function (Message $message) { })->wasThrown(ExitException::class);

        that($logs)->matchesCountEquals([
            '#^\\[\\d+\\]sleep:#' => 2,
        ]);
        that($events)->hasKey("standup");
    }

    function test_signal()
    {
        $logs   = [];
        $worker = new Worker([
            'driver'  => $this->createDriver(function ($count) {
                if ($count === 1) {
                    posix::kill(getmypid(), pcntl::SIGUSR2);
                }
                if ($count === 2) {
                    return new Message($count, $count, 0, 0);
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

        $worker->start(function (Message $message) {
            if ($message->getContents() === "2") {
                posix::kill(getmypid(), pcntl::SIGTERM);
                $this->sleep(5);
            }
        });

        that($logs)->contains(pcntl::SIGUSR2);
        that($logs)->notContains(pcntl::SIGTERM);
    }

    function test_timeout()
    {
        $worker = new Worker([
            'driver'   => $this->createDriver(function ($count) {
                if ($count === 1) {
                    return new Message($count, $count, 0, 3);
                }
                if ($count === 2) {
                    return new Message($count, $count, 0, 0);
                }
                if ($count === 3) {
                    posix::kill(getmypid(), pcntl::SIGTERM);
                }
                return null;
            }),
            'timeout'  => 1,
            'logger'   => new ArrayLogger($logs),
            'listener' => new ArrayListener($events),
        ]);

        $worker->start(function (Message $message) {
            while (true) {
                pcntl::signal_dispatch();
                usleep(10_000);
            }
        });

        that($logs)->matchesCountEquals([
            '#^\\[\\d+\\]timeout: 3.\\d#' => 1,
            '#^\\[\\d+\\]timeout: 1.\\d#' => 1,
        ]);
        that($events)->isSame([
            "timeout"  => ["1", "2"],
            "finish"   => ["1", "2"],
            "cycle"    => [0, 1, 2],
            "breather" => [2],
        ]);
    }

    function test_exception()
    {
        $worker = new Worker([
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
                return new Message($count, $count, 0, 0);
            }),
            'logger'  => new ArrayLogger($logs),
            'signals' => [],
        ]);

        $worker->start(function () { throw new Exception(); });

        that($logs)->matchesCountEquals([
            '#^\\[\\d+\\]start:#'     => 1,
            '#^\\[\\d+\\]begin:#'     => 1,
            '#^\\[\\d+\\]job:#'       => null,
            '#^\\[\\d+\\]exception:#' => 1,
            '#^\\[\\d+\\]end:#'       => 1,
        ]);
    }

    function test_error()
    {
        $worker = new Worker([
            'driver'  => $this->createDriver(function () { return new Message(123, 'dummy', 0, 0); }),
            'logger'  => new ArrayLogger($logs),
            'signals' => [],
        ]);

        try {
            $worker->start(function () { throw new Error('error message'); });
            $this->fail('no error');
        }
        catch (Error $e) {
            that($e)->getMessage()->is('error message');
        }

        that($logs)->matchesCountEquals([
            '#^\\[\\d+\\]start:#' => 1,
            '#^\\[\\d+\\]begin:#' => 1,
            '#^\\[\\d+\\]job:#'   => null,
            '#^\\[\\d+\\]error:#' => 1,
            '#^\\[\\d+\\]end:#'   => 0,
        ]);
    }

    function test_continuity()
    {
        $worker = new Worker([
            'driver'  => $this->createDriver(function ($count) {
                if ($count > 256) {
                    posix::kill(getmypid(), pcntl::SIGTERM);
                }
                return new Message($count, $count, 0, 0);
            }),
            'logger'  => new ArrayLogger($logs),
            'signals' => [],
        ]);

        $worker->start(function () { });

        that($logs)->matchesCountEquals([
            '#^\\[\\d+\\]continue: 16#'  => 1,
            '#^\\[\\d+\\]continue: 32#'  => 1,
            '#^\\[\\d+\\]continue: 64#'  => 1,
            '#^\\[\\d+\\]continue: 128#' => 1,
            '#^\\[\\d+\\]continue: 256#' => 1,
            '#^\\[\\d+\\]continue: 257#' => 1,
        ]);
    }

    function test_restart()
    {
        $worker = that(new Worker([
            'driver'  => $this->createDriver(function () { return null; }),
            'logger'  => new ArrayLogger($logs),
            'restart' => fn() => true,
        ]));

        // closure
        $worker->restartClosure(fn() => 123)(0, 0)->is(123);
        $worker->restartClosure(fn() => null)(0, 0)->is(null);

        // int
        $worker->restartClosure(11)(time() - 10, 0, 1)->is(null);
        $worker->restartClosure(11)(time() - 12, 0, 2)->is(1);

        // change
        $worker->restartClosure('change')(time(), 0, 1)->is(null);
        touch(__FILE__);
        $worker->restartClosure('change')(time() - 2, 0, 2)->is(1);

        // null or default
        $worker->restartClosure(null)(0, 0, 0)->is(null);

        $worker->start(function () { })->wasThrown(ExitException::class);
    }
}
