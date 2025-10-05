<?php
/**
 * @codeCoverageIgnore this is minimally emulation on windows for test
 */
namespace ryunosuke\hellowo\ext;

use ReflectionClass;

// This constant is only for property assignment dynamically(expression) and has no other meaning
foreach ([
    'SIG_ERR'     => -1,
    'SIG_DFL'     => 0,
    'SIG_IGN'     => 1,
    'SIG_BLOCK'   => 0,
    'SIG_UNBLOCK' => 1,
    'SIG_SETMASK' => 2,
    'WNOHANG'     => 1,
    'WUNTRACED'   => 1,
] as $name => $value) {
    define(__NAMESPACE__ . "\\$name", defined($name) ? constant($name) : $value);
}

class pcntl
{
    public const SIG_ERR = SIG_ERR;
    public const SIG_DFL = SIG_DFL;
    public const SIG_IGN = SIG_IGN;

    public const SIG_BLOCK   = SIG_BLOCK;
    public const SIG_UNBLOCK = SIG_UNBLOCK;
    public const SIG_SETMASK = SIG_SETMASK;

    public const WNOHANG   = WNOHANG;
    public const WUNTRACED = WUNTRACED;

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
    private static array $block_signals = [];
    private static float $alarm_start   = 0;
    private static int   $alarm_seconds = 0;

    private static int $errno = 0;

    public static function errno(): int
    {
        if (function_exists('pcntl_errno')) {
            return pcntl_errno();
        }

        return self::$errno;
    }

    public static function strerror(int $error_code): string
    {
        if (function_exists('pcntl_strerror')) {
            return pcntl_strerror($error_code);
        }

        $messages = [
            0 => 'success',
            1 => 'fork failed',
        ];
        return $messages[$error_code] ?? 'unknown error';
    }

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

    public static function sigprocmask(int $mode, array $signals, ?array &$old_signals = null): bool
    {
        if (function_exists('pcntl_sigprocmask')) {
            return pcntl_sigprocmask($mode, $signals, $old_signals);
        }

        $old_signals = array_keys(self::$block_signals);
        if ($mode === self::SIG_BLOCK) {
            self::$block_signals = array_replace(self::$block_signals, array_flip($old_signals));
        }
        if ($mode === self::SIG_UNBLOCK) {
            self::$block_signals = array_diff_key(self::$block_signals, array_flip($old_signals));
        }
        if ($mode === self::SIG_SETMASK) {
            self::$block_signals = array_flip($old_signals);
        }

        return true;
    }

    public static function signal_dispatch(): bool
    {
        if (function_exists('pcntl_signal_dispatch')) {
            return pcntl_signal_dispatch();
        }

        $signals = $GLOBALS['hellowo-processes'][getmypid()]['signal'] ?? [];
        unset($GLOBALS['hellowo-processes'][getmypid()]['signal']);

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

    public static function fork(): int
    {
        if (function_exists('pcntl_fork')) {
            return pcntl_fork();
        }

        static $count = 0;

        // mypid: 12345
        // child: 123450
        // child: 123451 => failed fork
        // child: 123452
        // child: 123453 => exited 3
        // child: 123454
        // child: 123455 => signaled 15
        // child: 123456
        // child: 123457 => failed kill
        // child: 123458
        // child: 123459 => stopped 19

        $pid = getmypid() * 10 + $count++;
        if ($pid % 10 === 1) {
            self::$errno = 1;
            return -1;
        }

        $GLOBALS['hellowo-processes'][$pid] = ['type' => 'child', 'live' => true];
        return $pid;
    }

    public static function wait(?int &$status, int $flags = 0, array &$resource_usage = []): int
    {
        if (function_exists('pcntl_wait')) {
            return pcntl_wait($status, $flags, $resource_usage);
        }

        return self::waitpid(-1, $status, $flags, $resource_usage);
    }

    public static function waitpid(int $process_id, ?int &$status, int $flags = 0, array &$resource_usage = []): int
    {
        if (function_exists('pcntl_waitpid')) {
            return pcntl_waitpid($process_id, $status, $flags, $resource_usage);
        }

        foreach ($GLOBALS['hellowo-processes'] ?? [] as $pid => $process) {
            if ($pid !== getmypid()) {
                $signals = $process['signal'] ?? [];
                foreach ($signals as $signal) {
                    if ($signal !== self::SIGCHLD) {
                        $GLOBALS['hellowo-processes'][$pid]['live'] = false;
                    }
                }
                $GLOBALS['hellowo-processes'][$pid]['signal'] = [];
            }
        }

        if ($process_id === -1) {
            foreach ($GLOBALS['hellowo-processes'] ?? [] as $pid => $process) {
                if ($pid !== getmypid() && !($process['live'] ?? true)) {
                    $process_id = $pid;
                    break;
                }
            }
        }

        if ($GLOBALS['hellowo-processes'][$process_id]['live'] ?? true) {
            return 0;
        }

        $status = 1 << 8 | 0;
        if ($process_id % 10 === 3) {
            $status = 1 << 8 | 3;
        }
        if ($process_id % 10 === 5) {
            $status = 2 << 8 | self::SIGTERM;
        }
        if ($process_id % 10 === 9) {
            $status = 3 << 8 | self::SIGSTOP;
        }

        unset($GLOBALS['hellowo-processes'][$process_id]);
        return $process_id;
    }

    public static function wifexited(int $status): bool
    {
        if (function_exists('pcntl_wifexited')) {
            return pcntl_wifexited($status);
        }

        return $status >> 8 === 1;
    }

    public static function wifstopped(int $status): bool
    {
        if (function_exists('pcntl_wifstopped')) {
            return pcntl_wifstopped($status);
        }

        return $status >> 8 === 2;
    }

    public static function wifsignaled(int $status): bool
    {
        if (function_exists('pcntl_wifsignaled')) {
            return pcntl_wifsignaled($status);
        }

        return $status >> 8 === 3;
    }

    public static function wexitstatus(int $status): int
    {
        if (function_exists('pcntl_wexitstatus')) {
            return pcntl_wexitstatus($status);
        }

        return $status & 0b0000000011111111;
    }

    public static function wstopsig(int $status): int
    {
        if (function_exists('pcntl_wstopsig')) {
            return pcntl_wstopsig($status);
        }

        return $status & 0b0000000011111111;
    }

    public static function wtermsig(int $status): int
    {
        if (function_exists('pcntl_wtermsig')) {
            return pcntl_wtermsig($status);
        }

        return $status & 0b0000000011111111;
    }

    public static function wstatus(int $status): array
    {
        $result = [];

        if (self::wifexited($status)) {
            $result['status'] = 'exited';
            $result['code']   = self::wexitstatus($status);
        }
        if (self::wifsignaled($status)) {
            $result['status'] = 'signaled';
            $result['code']   = self::wtermsig($status);
        }
        if (self::wifstopped($status)) {
            $result['status'] = 'stopped';
            $result['code']   = self::wstopsig($status);
        }

        return $result;
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
