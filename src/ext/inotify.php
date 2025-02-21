<?php
/**
 * @codeCoverageIgnore this is minimally emulation on windows for test
 */
namespace ryunosuke\hellowo\ext;

// This constant is only for property assignment dynamically(expression) and has no other meaning
foreach ([
    'IN_MODIFY'      => 1 << 1,
    'IN_ATTRIB'      => 1 << 2,
    'IN_CLOSE_WRITE' => 1 << 3,
    'IN_MOVED_FROM'  => 1 << 6,
    'IN_MOVED_TO'    => 1 << 7,
    'IN_CREATE'      => 1 << 8,
    'IN_DELETE'      => 1 << 9,
] as $name => $value) {
    define(__NAMESPACE__ . "\\$name", defined($name) ? constant($name) : $value);
}

class inotify
{
    public const IN_MODIFY      = IN_MODIFY;
    public const IN_ATTRIB      = IN_ATTRIB;
    public const IN_CLOSE_WRITE = IN_CLOSE_WRITE;
    public const IN_MOVED_FROM  = IN_MOVED_FROM;
    public const IN_MOVED_TO    = IN_MOVED_TO;
    public const IN_CREATE      = IN_CREATE;
    public const IN_DELETE      = IN_DELETE;

    private static array $resources    = [];
    private static int   $watcherCount = 0;

    public static function init() /*resource*/
    {
        if (function_exists('inotify_init')) {
            return inotify_init();
        }

        $resource = tmpfile();

        self::$resources[(int) $resource] = [];
        return $resource;
    }

    public static function add_watch(/*resource*/ $inotify_instance, string $pathname, int $mask): int
    {
        if (function_exists('inotify_add_watch')) {
            return inotify_add_watch($inotify_instance, $pathname, $mask);
        }

        self::$resources[(int) $inotify_instance][++self::$watcherCount] = [$pathname, $mask, self::gather($pathname)];
        return self::$watcherCount;
    }

    public static function read(/*resource*/ $inotify_instance, float $timeout = 0): array
    {
        if (function_exists('inotify_read')) {
            $read  = [$inotify_instance];
            $write = $except = null;
            @stream_select($read, $write, $except, 0, $timeout * 1000 * 1000);
            foreach ($read as $fp) {
                return inotify_read($fp);
            }
            return [];
        }

        $resource_id = (int) $inotify_instance;
        for ($i = 0; $i < 10; $i++) {
            $events = [];
            foreach (self::$resources[$resource_id] as $watch_descriptor => [$pathname, $mask, $files]) {
                $current = self::$resources[$resource_id][$watch_descriptor][2] = self::gather($pathname);

                if ($mask & (self::IN_CREATE | self::IN_MOVED_TO)) {
                    foreach (array_diff_key($current, $files) as $file) {
                        $events[] = [
                            'wd'     => $watch_descriptor,
                            'mask'   => $mask,
                            'cookie' => self::IN_CREATE,
                            'name'   => $file['basename'],
                        ];
                    }
                }
                if ($mask & (self::IN_DELETE | self::IN_MOVED_FROM)) {
                    foreach (array_diff_key($files, $current) as $file) {
                        $events[] = [
                            'wd'     => $watch_descriptor,
                            'mask'   => $mask,
                            'cookie' => self::IN_DELETE,
                            'name'   => $file['basename'],
                        ];
                    }
                }
                if ($mask & (self::IN_MODIFY | self::IN_CLOSE_WRITE)) {
                    foreach (array_intersect_key($files, $current) as $name => $file) {
                        if ($files[$name] !== $current[$name]) {
                            $events[] = [
                                'wd'     => $watch_descriptor,
                                'mask'   => $mask,
                                'cookie' => self::IN_MODIFY,
                                'name'   => $file['basename'],
                            ];
                        }
                    }
                }
            }
            if ($events) {
                return $events;
            }
            usleep($timeout * 1000 * 100);
        }
        return [];
    }

    public static function rm_watch(/*resource*/ $inotify_instance, int $watch_descriptor): bool
    {
        if (function_exists('inotify_rm_watch')) {
            return inotify_rm_watch($inotify_instance, $watch_descriptor);
        }

        unset(self::$resources[(int) $inotify_instance][$watch_descriptor]);
        return true;
    }

    public static function close(/*resource*/ $inotify_instance): bool
    {
        if (function_exists('init')) {
            return fclose($inotify_instance);
        }

        unset(self::$resources[(int) $inotify_instance]);
        return fclose($inotify_instance);
    }

    private static function gather(string $pathname): array
    {
        $result = [];
        foreach (glob("$pathname/*", GLOB_NOESCAPE) as $filename) {
            $result[$filename] = [
                'basename'  => basename($filename),
                'fileowner' => fileowner($filename),
                'filegroup' => filegroup($filename),
                'fileperms' => fileperms($filename),
                'filemtime' => filemtime($filename),
                'content'   => is_dir($filename) ? scandir($filename) : sha1_file($filename),
            ];
        }
        return $result;
    }
}
