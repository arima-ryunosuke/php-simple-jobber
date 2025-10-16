<?php
/**
 * @codeCoverageIgnore this is minimally emulation on windows for test
 */
namespace ryunosuke\hellowo\ext;

class posix
{
    private static int $errno = 0;

    public static function errno(): int
    {
        if (function_exists('posix_errno')) {
            return posix_errno();
        }

        return self::$errno;
    }

    public static function strerror(int $error_code): string
    {
        if (function_exists('posix_strerror')) {
            return posix_strerror($error_code);
        }

        $messages = [
            0 => 'success',
            2 => 'kill failed',
        ];
        return $messages[$error_code] ?? 'unknown error';
    }

    public static function kill(int $process_id, int $signal): bool
    {
        if (function_exists('posix_kill')) {
            return posix_kill($process_id, $signal);
        }

        if ($process_id !== getmypid() && $process_id % 10 === 7) {
            self::$errno = 2;
            return false;
        }

        if (!isset($GLOBALS['hellowo-processes'][$process_id])) {
            return false;
        }

        $GLOBALS['hellowo-processes'][$process_id]['signal'][] = $signal;

        return true;
    }

    public static function proc_cmdline(string $newname = ''): string
    {
        if (strlen($newname)) {
            $oldname = posix::proc_cmdline();
            cli_set_process_title($newname);

            if (DIRECTORY_SEPARATOR === '\\') {
                $GLOBALS['hellowo-processes'][getmypid()]['cmdline'] = $newname;
            }

            return $oldname;
        }
        else {
            $title = cli_get_process_title();
            // first call returns "". once set, it returns it
            if (!strlen($title)) {
                if (file_exists($cmdline = "/proc/" . getmypid() . "/cmdline")) {
                    $title = trim(implode(' ', explode("\0", file_get_contents($cmdline))));
                }
            }
            return $title;
        }
    }

    public static function pgrep(string $pattern): array
    {
        if (DIRECTORY_SEPARATOR === '/' && file_exists("/proc")) {
            $result = [];
            foreach (glob("/proc/*") as $proc) {
                $pid = basename($proc);
                if (ctype_digit($pid) && file_exists($cmdline = "$proc/cmdline")) {
                    $cmdline = trim(implode(' ', explode("\0", file_get_contents($cmdline))));
                    if (preg_match($pattern, $cmdline)) {
                        $result[$pid] = $cmdline;
                    }
                }
            }
            return $result;
        }

        $result = [];
        foreach ($GLOBALS['hellowo-processes'] ?? [] as $pid => $process) {
            $cmdline = $process['cmdline'] ?? '';
            if (preg_match($pattern, $cmdline)) {
                $result[$pid] = $cmdline;
            }
        }

        return $result;
    }
}
