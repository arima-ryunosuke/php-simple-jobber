<?php
/**
 * @codeCoverageIgnore this is minimally emulation on windows for test
 */
namespace ryunosuke\hellowo\ext;

use ReflectionClass;

// This constant is only for property assignment dynamically(expression) and has no other meaning
foreach ([
    'SIG_ERR' => -1,
    'SIG_DFL' => 0,
    'SIG_IGN' => 1,
] as $name => $value) {
    define(__NAMESPACE__ . "\\$name", defined($name) ? constant($name) : $value);
}

class pcntl
{
    public const SIG_ERR = SIG_ERR;
    public const SIG_DFL = SIG_DFL;
    public const SIG_IGN = SIG_IGN;

    public const SIGHUP    = 1;
    public const SIGINT    = 2;
    public const SIGQUIT   = 3;
    public const SIGILL    = 4;
    public const SIGTRAP   = 5;
    public const SIGABRT   = 6;
    public const SIGIOT    = 6;
    public const SIGBUS    = 7;
    public const SIGFPE    = 8;
    public const SIGKILL   = 9;
    public const SIGUSR1   = 10;
    public const SIGSEGV   = 11;
    public const SIGUSR2   = 12;
    public const SIGPIPE   = 13;
    public const SIGALRM   = 14;
    public const SIGTERM   = 15;
    public const SIGSTKFLT = 16;
    public const SIGCLD    = 17;
    public const SIGCHLD   = 17;
    public const SIGCONT   = 18;
    public const SIGSTOP   = 19;
    public const SIGTSTP   = 20;
    public const SIGTTIN   = 21;
    public const SIGTTOU   = 22;
    public const SIGURG    = 23;
    public const SIGXCPU   = 24;
    public const SIGXFSZ   = 25;
    public const SIGVTALRM = 26;
    public const SIGPROF   = 27;
    public const SIGWINCH  = 28;
    public const SIGPOLL   = 29;
    public const SIGIO     = 29;
    public const SIGPWR    = 30;
    public const SIGSYS    = 31;
    public const SIGBABY   = 31;

    private static bool  $async_signals = false;
    private static array $handlers      = [];
    private static float $alarm_start   = 0;
    private static int   $alarm_seconds = 0;

    public static function async_signals(?bool $enable = null): bool
    {
        if (function_exists('pcntl_async_signals')) {
            return pcntl_async_signals($enable);
        }

        if ($enable === null) {
            return self::$async_signals;
        }

        if ($enable) {
            register_tick_function(__CLASS__ . '::' . 'signal_dispatch');
        }
        else {
            unregister_tick_function(__CLASS__ . '::' . 'signal_dispatch');
        }

        $return              = self::$async_signals;
        self::$async_signals = $enable;

        return $return;
    }

    public static function signal_dispatch(): bool
    {
        if (function_exists('pcntl_signal_dispatch')) {
            return pcntl_signal_dispatch();
        }

        $signalfile = \ryunosuke\hellowo\API::$processDirectory . '/' . getmypid() . '/signal';
        $signals    = array_map('intval', @file($signalfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        file_put_contents($signalfile, '', LOCK_EX);

        if (self::$alarm_seconds > 0 && microtime(true) >= (self::$alarm_start + self::$alarm_seconds)) {
            self::$alarm_seconds = 0;
            $signals[]           = self::SIGALRM;
        }

        foreach ($signals as $signal) {
            if (isset(self::$handlers[$signal])) {
                $handler = self::$handlers[$signal];

                if ($handler === self::SIG_DFL) {
                    exit;
                }
                if ($handler === self::SIG_IGN) {
                    continue;
                }

                $handler($signal, null);
            }
        }

        return true;
    }

    public static function signal(int $signal, $handler, bool $restart_syscalls = true): bool
    {
        if (function_exists('pcntl_signal')) {
            return pcntl_signal($signal, $handler, $restart_syscalls);
        }

        self::$handlers[$signal] = $handler;

        return true;
    }

    public static function signal_get_handler(int $signal)
    {
        if (function_exists('pcntl_signal_get_handler')) {
            return pcntl_signal_get_handler($signal);
        }

        return self::$handlers[$signal] ?? self::SIG_DFL;
    }

    public static function alarm(int $seconds): int
    {
        if (function_exists('pcntl_alarm')) {
            return pcntl_alarm($seconds);
        }

        self::$alarm_start = microtime(true);

        $return              = self::$alarm_seconds;
        self::$alarm_seconds = $seconds;

        return $return;
    }

    public static function strsignal(int $signal): ?string
    {
        static $signals = null;
        $signals ??= array_flip(
            array_filter(
                (new ReflectionClass(self::class))->getConstants(),
                fn($name) => preg_match("/^SIG[A-Z]/i", $name),
                ARRAY_FILTER_USE_KEY,
            ),
        );

        return $signals[$signal] ?? null;
    }
}
