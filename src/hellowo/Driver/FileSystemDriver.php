<?php

namespace ryunosuke\hellowo\Driver;

use Exception;
use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ryunosuke\hellowo\ext\inotify;
use ryunosuke\hellowo\Message;

/**
 * architecture:
 * atomicity is ensured by renaming during running.
 * mtime is used to schedule running.
 * worked files past TTR will be reverted.
 */
class FileSystemDriver extends AbstractDriver
{
    public static function isEnabled(): bool
    {
        return true;
    }

    protected static function normalizeParams(array $params): array
    {
        $parts = explode('.', $params['path'] ?? '', 2);
        return [
            'directory' => $parts[0] ?? null,
            'extension' => $parts[1] ?? null,
        ];
    }

    private string $directory;
    private string $extension;
    private string $working;
    private string $dead;

    private ?float $starttime;
    private float  $waittime;
    private string $waitmode;
    private int    $ttr;

    private $inotify;
    private $watcher;

    public function __construct(array $options)
    {
        $options = self::normalizeOptions($options, [
            // watching directory and extension
            'directory' => '',
            'extension' => 'job',
            // null: wait waittime simply, int: wait until starttime+waittime
            'starttime' => null,
            // one cycle wait time
            'waittime'  => 10.0,
            // inotify: use inotify, php: call usleep
            'waitmode'  => 'php',
            // max time to run
            'ttr'       => 60 * 60 * 24,
        ]);

        assert(strlen($options['directory']));
        assert(strlen($options['extension']));

        $this->directory = $options['directory'];
        $this->extension = $options['extension'];
        $this->working   = "$this->directory/.working";
        $this->dead      = "$this->directory/.dead";

        $this->starttime = $options['starttime'];
        $this->waittime  = $options['waittime'];
        $this->waitmode  = $options['waitmode'];
        $this->ttr       = $options['ttr'];

        parent::__construct("filesystem {$options['directory']}/*.{$options['extension']}");
    }

    protected function setup(bool $forcibly = false): void
    {
        if ($forcibly) {
            if (file_exists($this->directory)) {
                $rdi = new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS);
                $rii = new RecursiveIteratorIterator($rdi, RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($rii as $entry) {
                    ($entry->isLink() || $entry->isFile() ? 'unlink' : 'rmdir')($entry);
                }
            }
        }

        if (!file_exists($this->directory)) {
            @mkdir($this->directory, 0777, true);
        }
        if (!file_exists($this->working)) {
            @mkdir($this->working, 0777, true);
        }
        if (!file_exists($this->dead)) {
            @mkdir($this->dead, 0777, true);
        }
    }

    protected function daemonize(): void
    {
        if ($this->waitmode === 'inotify') {
            $this->inotify = inotify::init();
            $this->watcher = inotify::add_watch($this->inotify, $this->directory, inotify::IN_MOVED_TO | inotify::IN_CLOSE_WRITE);
        }

        parent::daemonize();
    }

    protected function isStandby(): bool
    {
        return !is_writable($this->directory);
    }

    protected function select(): Generator
    {
        clearstatcache();

        // detect target files
        $targets = glob("$this->directory/*.$this->extension");
        foreach ($targets as $filepath) {
            if ($this->expired($filepath, time())) {
                $workfile = "$this->working/" . basename($filepath);

                // renames fail at race condition
                if (@rename($filepath, $workfile)) {
                    $job    = $this->decode(file_get_contents($workfile));
                    $result = yield new Message(basename($filepath), $job['contents'], $job['retry']);
                    if ($result === null) {
                        unlink($workfile);
                    }
                    elseif (is_int($result) || is_float($result)) {
                        $job['retry']++;
                        file_put_contents($workfile, $this->encode($job));
                        rename($workfile, $filepath);
                        touch($filepath, ceil(time() + $result));
                    }
                    else {
                        $job['error'] = (string) $result;
                        file_put_contents("$this->dead/" . basename($filepath), json_encode($job));
                        unlink($workfile);
                    }
                    return;
                }
            }
        }

        $this->sleep();
        $this->recover();
    }

    protected function error(Exception $e): bool
    {
        return false;
    }

    protected function close(): void
    {
        if ($this->watcher) {
            inotify::rm_watch($this->inotify, $this->watcher);
            $this->watcher = null;
        }
        if ($this->inotify) {
            inotify::close($this->inotify);
            $this->inotify = null;
        }
    }

    protected function send(string $contents, ?int $priority = null, ?float $delay = null): ?string
    {
        $tmpname = tempnam(sys_get_temp_dir(), sprintf('%03d', 999 - ($priority ?? 500)));
        file_put_contents($tmpname, $this->encode(['contents' => $contents]));
        touch($tmpname, time() + ceil($delay ?? 0));

        $jobname = "$this->directory/" . basename($tmpname) . uniqid('', true) . ".$this->extension";
        rename($tmpname, $jobname);

        return $jobname;
    }

    protected function notify(int $count = 1): int
    {
        if ($this->waitmode === 'inotify') {
            // do nothing
            return 0;
        }
        elseif ($this->waitmode === 'php') {
            return count(parent::notifyLocal($count));
        }
    }

    protected function cancel(?string $job_id = null, ?string $contents = null): int
    {
        $count   = 0;
        $targets = glob($this->directory . '/*.' . $this->extension);
        foreach ($targets as $filepath) {
            if (!is_dir($filepath)) {
                $matched = false;
                $matched = $matched || $job_id !== null && $filepath === $job_id;
                $matched = $matched || $contents !== null && $this->decode(file_get_contents($filepath))['contents'] === $contents;
                if ($matched && unlink($filepath)) {
                    $count++;
                }
            }
        }
        return $count;
    }

    protected function clear(): int
    {
        $count   = 0;
        $targets = glob($this->directory . '/*.' . $this->extension);
        foreach ($targets as $filepath) {
            if (!is_dir($filepath) && unlink($filepath)) {
                $count++;
            }
        }
        return $count;
    }

    protected function sleep(): void
    {
        if ($this->waitmode === 'inotify') {
            $events = inotify::read($this->inotify, $this->waittime);
            foreach ($events as $event) {
                if (pathinfo($event['name'], PATHINFO_EXTENSION) === $this->extension && file_exists("$this->directory/{$event['name']}")) {
                    return;
                }
            }
        }
        elseif ($this->waitmode === 'php') {
            usleep(intval($this->waitTime($this->starttime, $this->waittime) * 1000 * 1000));
        }
    }

    protected function recover(): array
    {
        $result    = [];
        $workfiles = glob("$this->working/*.$this->extension");
        foreach ($workfiles as $workfile) {
            if ($this->expired($workfile, time() - $this->ttr)) {
                $fname = "$this->directory/" . basename($workfile);

                // renames fail at race condition
                if (@rename($workfile, $fname)) {
                    $result[$workfile] = $fname;
                }
            }
        }
        return $result;
    }

    private function expired(string $filename, int $period): bool
    {
        if (!file_exists($filename) || is_dir($filename)) {
            return false;
        }

        // file does not exists at race condition
        $filemtime = @filemtime($filename);
        if ($filemtime === false) {
            return false; // @codeCoverageIgnore
        }

        return $filemtime <= $period;
    }
}
