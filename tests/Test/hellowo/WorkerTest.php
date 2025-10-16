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

    function test_work()
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
            $worker->work(function (Message $message) use ($stdout) {
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
            '#^\\[\\d+\\]start:#'   => 1,
            '#^\\[\\d+\\]begin:#'   => 1,
            '#^\\[\\d+\\]job:#'     => null,
            '#^\\[\\d+\\]done:#'    => null,
            '#^\\[\\d+\\]fail:#'    => 1,
            '#^\\[\\d+\\]timeout:#' => 1,
            '#^\\[\\d+\\]retry:#'   => 3,
            '#^\\[\\d+\\]finish:#'  => null,
            '#^\\[\\d+\\]end:#'     => 0,
        ]);

        that($events)->isSame([
            "cycle"   => [0, 1, 2, 3, 4, 5, 6, 7],
            "fail"    => ["2"],
            "finish"  => ["2", "3", "4", "4", "4", "4", "5"],
            "timeout" => ["3"],
            "retry"   => ["4", "4", "4"],
            "done"    => ["4", "5"],
        ]);
    }

    function test_fork()
    {
        if (extension_loaded('pcntl')) {
            $this->markTestSkipped();
        }

        $worker = that(new Worker([
            'driver'  => $this->createDriver(fn() => null),
            'logger'  => new ArrayLogger($logs),
            'signals' => [],
            'timeout' => 1,
        ]));

        $ipcSockets = stream_socket_pair(DIRECTORY_SEPARATOR === '\\' ? STREAM_PF_INET : STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($ipcSockets[0], false);
        stream_set_blocking($ipcSockets[1], false);

        /** @var Generator $generator */
        $generator = $worker->generateFork(fn() => null, 4, 8, $ipcSockets)->return();

        $processData = function ($pdata) {
            $result = [];
            foreach ($pdata as $pid => $data) {
                $result[$pid - getmypid() * 10] = $data['type'];
            }
            return $result;
        };

        fwrite($ipcSockets[1], "hoge\n");
        $generator->send(0.1);
        that($processData($generator->current()))->is([
            0 => "initial",  // initial
            // 1 is failed fork
            2 => "initial",  // initial
            3 => "initial",  // initial
        ]);

        fwrite($ipcSockets[1], str_repeat("increase\n", 9));
        $generator->send(0.1);
        that($processData($generator->current()))->is([
            0 => "initial",  // initial
            // 1 is failed fork
            2 => "initial",  // initial
            3 => "initial",  // initial
            4 => "initial",  // respawn(because 1 is failed)
            5 => "increase", // increase
            6 => "increase", // increase
            7 => "increase", // increase
            8 => "increase", // increase
            // 9 is busy
        ]);

        fwrite($ipcSockets[1], str_repeat("decrease\n", 9));
        $generator->send(0.1);
        that($processData($generator->current()))->is([
            0 => "initial",  // initial
            // 1 is failed fork
            2 => "initial",  // initial
            3 => "initial",  // respawn
            4 => "initial",  // initial
            // 5 is decrease
            // 6 is decrease
            7 => "increase", // failed kill
            // 8 is decrease
        ]);

        fwrite($ipcSockets[1], str_repeat("increase\n", 9));
        posix::kill(getmypid(), pcntl::SIGHUP);
        $generator->send(0.1);
        that($processData($generator->current()))->is([
            7 => "increase", // failed kill
        ]);
        $generator->send(0.1);
        that($processData($generator->current()))->is([
            7  => "increase", // failed kill
            // 8 is increase and failed respawn
            // 9 is increase and failed respawn
            // 10 is increase and failed respawn
            // 11 is failed fork
            // 12 is increase and failed respawn
            13 => "initial", // respawn
            14 => "initial", // respawn
            15 => "initial", // respawn
        ]);

        $pids = $generator->current();
        end($pids);
        posix::kill(key($pids), pcntl::SIGTERM);
        posix::kill(getmypid(), pcntl::SIGCHLD);
        $generator->send(0.1);
        that($processData($generator->current()))->is([
            7  => "increase", // failed kill
            13 => "initial",  // reload
            14 => "initial",  // reload
            // 15 is kill and reap
        ]);

        fwrite($ipcSockets[1], str_repeat("increase\n", 9));
        posix::kill(getmypid(), pcntl::SIGTERM);
        $generator->send(0.1);
        that($processData($generator->getReturn()))->is([
            7  => "increase", // failed kill
            // 15 is respawn and killed by loop end
            // 16 is respawn and killed by loop end
            17 => "increase", // failed kill
        ]);

        that($logs)->matchesCountEquals([
            '#^\\[\\d+\\]\\[master\\]fork:#'     => null,
            '#^\\[\\d+\\]\\[master\\]kill:#'     => null,
            '#^\\[\\d+\\]\\[master\\]message:#'  => null,
            '#^\\[\\d+\\]\\[master\\]busy: you#' => null,
            '#^\\[\\d+\\]\\[master\\]reload:#'   => null,
            '#^\\[\\d+\\]\\[master\\]reap:#'     => null,
            '#^\\[\\d+\\]\\[master\\]stop:#'     => null,
            '#unknown message#'                  => null,
            '#respawn: failed#'                  => null,
            '#fork failed#'                      => null,
            '#kill failed#'                      => null,
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
            $worker->work(function (Message $message) use ($stdout) {
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

        $worker->work(function (Message $message) { })->wasThrown(ExitException::class);

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

        $worker->work(function (Message $message) {
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

        $worker->work(function (Message $message) {
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
            "timeout" => ["1", "2"],
            "finish"  => ["1", "2"],
            "cycle"   => [0, 1, 2],
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

        $worker->work(function () { throw new Exception(); });

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
            $worker->work(function () { throw new Error('error message'); });
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
        $worker = that(new Worker([
            'driver'  => $this->createDriver(function ($count) {
                if ($count > 512) {
                    posix::kill(getmypid(), pcntl::SIGTERM);
                }
                if ($count > 256) {
                    return null;
                }
                return new Message($count, $count, 0, 0);
            }),
            'logger'  => new ArrayLogger($logs),
            'signals' => [],
        ]));

        foreach ($worker->generateWork(function () { }, [null, $tmpfile = tmpfile()])->return() as $ignored) {
            // noop
        }

        that($logs)->matchesCountEquals([
            '#^\\[\\d+\\]busy:#'       => 5,
            '#^\\[\\d+\\]busy: 16/1#'  => 1,
            '#^\\[\\d+\\]busy: 32/2#'  => 1,
            '#^\\[\\d+\\]busy: 64/3#'  => 1,
            '#^\\[\\d+\\]busy: 128/4#' => 1,
            '#^\\[\\d+\\]busy: 256/5#' => 1,
            '#^\\[\\d+\\]idle:#'       => 5,
            // no fire
            // 245|+++++++++++| rate > 0 fire busy256
            // 257|+++++++++--| rate > 0 not fire idle256
            '#^\\[\\d+\\]idle: 255/4#' => 0,
            '#^\\[\\d+\\]idle: 127/3#' => 1,
            '#^\\[\\d+\\]idle: 63/2#'  => 1,
            '#^\\[\\d+\\]idle: 31/1#'  => 1,
            '#^\\[\\d+\\]idle: 15/0#'  => 1,
        ]);

        rewind($tmpfile);
        that(explode("\n", stream_get_contents($tmpfile)))->matchesCountEquals([
            '#increase#' => 5,
            '#decrease#' => 5,
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

        $worker->work(function () { })->wasThrown(ExitException::class);

        $ipcSockets = stream_socket_pair(DIRECTORY_SEPARATOR === '\\' ? STREAM_PF_INET : STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($ipcSockets[0], false);
        stream_set_blocking($ipcSockets[1], false);

        /** @var Generator $generator */
        $generator = $worker->generateFork(fn() => null, 4, 8, $ipcSockets);
        $generator->send(0.1)->wasThrown(ExitException::class);
    }

    function test_shutdown()
    {
        $worker = that(new Worker([
            'driver' => $this->createDriver(function ($count) { return new Message($count, $count, 0, 0); }),
            'logger' => new ArrayLogger($logs),
        ]));

        $receiver  = [];
        $generator = $worker->generateWork(function ($message) use (&$receiver) {
            if (strpos(ini_get('disable_functions'), 'register_shutdown_function') === false) {
                $GLOBALS['hellowo-shutdown_function'][] = [
                    function ($message) use (&$receiver) {
                        $receiver[] = $message->getId();
                    },
                    [$message],
                ];
            }
            else {
                register_shutdown_function(function ($message) use (&$receiver) {
                    $receiver[] = $message->getId();
                }, $message);
            }
        }, []);
        $generator->next();
        $generator->next();

        that($receiver)->is(["1", "2"]);
    }
}
