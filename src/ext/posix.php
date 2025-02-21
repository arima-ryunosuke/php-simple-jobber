<?php
/**
 * @codeCoverageIgnore this is minimally emulation on windows for test
 */
namespace ryunosuke\hellowo\ext;

class posix
{
    public static function kill(int $process_id, int $signal): bool
    {
        if (function_exists('posix_kill')) {
            return posix_kill($process_id, $signal);
        }

        $mypiddir = \ryunosuke\hellowo\API::$processDirectory . "/$process_id";
        if (!file_exists($mypiddir)) {
            return false;
        }

        $signalfile = "$mypiddir/signal";
        file_put_contents($signalfile, "$signal\n", FILE_APPEND | LOCK_EX);

        return true;
    }

    public static function proc_cmdline(string $newname = ''): string
    {
        if (strlen($newname)) {
            $oldname = posix::proc_cmdline();
            cli_set_process_title($newname);

            if (DIRECTORY_SEPARATOR === '\\') {
                $mypiddir = \ryunosuke\hellowo\API::$processDirectory . "/" . getmypid();
                @mkdir($mypiddir, 0777, true);

                $cmdline = "$mypiddir/cmdline";
                file_put_contents($cmdline, $newname, LOCK_EX);
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

    public static function pgrep(string $line): array
    {
        if (file_exists("/usr/bin/pgrep")) {
            exec("/usr/bin/pgrep -af '$line'", $output);
            $result = [];
            foreach ($output as $line) {
                $parts             = preg_split('#\\s+#u', $line, 2, PREG_SPLIT_NO_EMPTY);
                $result[$parts[0]] = $parts[1];
            }
            return $result;
        }

        $result = [];
        foreach (glob(\ryunosuke\hellowo\API::$processDirectory . "/*/cmdline") as $cmdline) {
            $pid = basename(dirname($cmdline));
            $cmd = file_get_contents($cmdline);
            if (strpos($cmd, $line) !== false) {
                $result[$pid] = $cmd;
            }
        }

        return $result;
    }
}
