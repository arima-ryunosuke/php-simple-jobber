<?php

namespace ryunosuke\hellowo\Driver;

use DateTime;
use Error;
use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;
use ryunosuke\hellowo\API;
use ryunosuke\hellowo\Exception\UnsupportedException;
use Throwable;

abstract class AbstractDriver extends API
{
    public static $driverMap = [
        'filesystem' => FileSystemDriver::class,
        'beanstalk'  => BeanstalkDriver::class,
        'gearman'    => GearmanDriver::class,
        'mysql'      => MySqlDriver::class,
        'pgsql'      => PostgreSqlDriver::class,
    ];

    public static function isEnabled(): bool
    {
        return true;
    }

    /** @return static */
    public static function create(string $url, array $additinalParams = []): self/*static*/
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $parts['query']);
        $parts = $additinalParams + $parts + $parts['query'];

        $parts['transport'] = [
            'host'     => $parts['host'] ?? null,
            'port'     => $parts['port'] ?? null,
            'username' => $parts['user'] ?? null,
            'password' => $parts['pass'] ?? null,
        ];

        assert(isset($parts['scheme']));
        assert(isset(self::$driverMap[$parts['scheme']]));

        /** @var AbstractDriver $driverClass */
        $driverClass = self::$driverMap[$parts['scheme']];
        $parts       = array_replace_recursive($parts, $driverClass::normalizeParams($parts));

        return new $driverClass($parts);
    }

    protected static function normalizeParams(array $params): array
    {
        return [];
    }

    /**
     * validate and merge option
     *
     * simple check with a type via $defaults.
     *
     * - null: no check allows all type/value
     * - bool: allows bool only
     * - int: allows int only
     * - float: allows numeric only
     * - string: checks empty if it has length
     * - resource: allows resource only
     * - callable: allows callable only
     * - object: allows subclass only
     * - array:
     *   - recursion if hash-array
     *   - allows list-array only if list-array
     *   - allows all object if object
     *
     * @param array $options
     * @param array $defaults
     * @return array normalized arguments
     */
    protected static function normalizeOptions(array $options, array $defaults): array
    {
        $result = array_replace_recursive($defaults, $options);

        assert((function () use ($result, $defaults, &$message) {
            $asserts = self::assertArguments($result, $defaults);
            $message = var_export($asserts, true);

            $flag = true;
            array_walk_recursive($asserts, function ($val) use (&$flag) {
                $flag = $flag && $val;
            });
            return $flag;
        })(), $message);

        return $result;
    }

    private static function assertArguments(array $arguments, array $defaults, string $parent = ''): array
    {
        $result = [];
        foreach ($defaults as $key => $value) {
            $path = "$parent/$key";
            if (!array_key_exists($key, $arguments)) {
                $result[$path] = false;
            }
            elseif (is_null($value)) {
                $result[$path] = true; // no check
            }
            elseif (is_bool($value)) {
                $result[$path] = is_bool($arguments[$key]);
            }
            elseif (is_int($value)) {
                $result[$path] = filter_var($arguments[$key], FILTER_VALIDATE_INT) !== false;
            }
            elseif (is_float($value)) {
                $result[$path] = filter_var($arguments[$key], FILTER_VALIDATE_FLOAT) !== false;
            }
            elseif (is_string($value) && strlen($value)) {
                $result[$path] = !!strlen($arguments[$key]);
            }
            elseif (is_resource($value)) {
                $result[$path] = is_resource($arguments[$key]);
            }
            elseif (is_callable($value)) {
                $result[$path] = is_callable($arguments[$key]);
            }
            elseif (is_object($value)) {
                $result[$path] = is_object($arguments[$key]) && is_a($arguments[$key], get_class($value));
            }
            elseif (is_array($value)) {
                if (is_array($arguments[$key])) {
                    if ($value === array_values($value)) {
                        $result[$path] = is_array($arguments[$key]) && $arguments[$key] === array_values($arguments[$key]);
                    }
                    else {
                        $result[$path] = self::assertArguments($arguments[$key], $value, $path);
                    }
                }
                else {
                    $result[$path] = is_object($arguments[$key]) || is_resource($arguments[$key]);
                }
            }
        }
        return $result;
    }

    /**
     * array convert to method's arguments
     *
     * php's built-in functions and classes don't support default values (php < 8).
     * in addition, the argument names differ depending on the environment and version.
     * e.g. PDO::__construct
     *
     * @param array $callable
     * @param array $argsuments
     * @return array named arguments
     */
    protected static function normalizeArguments(array $callable, array $argsuments): array
    {
        $result     = [];
        $parameters = (new ReflectionMethod(...$callable))->getParameters();
        foreach ($parameters as $parameter) {
            if (array_key_exists($position = $parameter->getPosition(), $argsuments)) {
                $result[] = $argsuments[$position];
            }
            elseif (array_key_exists($name = $parameter->getName(), $argsuments)) {
                $result[] = $argsuments[$name];
            }
            else {
                $result[] = $parameter->getDefaultValue();
            }
        }
        return $result;
    }

    private string $description;

    protected LoggerInterface $logger;

    public function __construct(string $description, ?LoggerInterface $logger)
    {
        $this->description = $description;
        $this->logger      = $logger ?? new NullLogger();
    }

    /**
     * toString for log
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->description;
    }

    public function transactional(callable $callback, ...$args)
    {
        $this->begin();
        try {
            $result = $callback(...$args);
            $this->commit();
            return $result;
        }
        catch (Throwable $t) {
            $this->rollback();
            throw $t;
        }
    }

    protected function begin() { }

    protected function commit() { }

    protected function rollback() { }

    protected function daemonize(): void { }

    protected function encode(array $contents): string
    {
        try {
            return json_encode(array_replace([
                'retry'   => 0,
                'timeout' => 0,
            ], $contents), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        catch (Exception $e) {
            throw new Error("failed to encode", 0, $e);
        }
    }

    protected function decode(string $contents): array
    {
        try {
            return array_replace([
                'retry'   => 0,
                'timeout' => 0,
            ], json_decode($contents, true, 512, JSON_THROW_ON_ERROR));
        }
        catch (Exception $e) {
            throw new Error("failed to decode", 0, $e);
        }
    }

    protected function getDelay(/*null|float|string|DateTimeInterface*/ $time): float
    {
        if (is_null($time)) {
            return 0;
        }
        if (is_int($time) || is_float($time) || is_numeric($time)) {
            return $time;
        }

        if (is_string($time)) {
            $time = new DateTime($time);
        }
        return max(0, $time->format('U.v') - microtime(true));
    }

    protected function shareJob(?string $sharedFile, float $waittime, int $nextlimit, callable $select, ?float $now = null): array
    {
        if ($sharedFile === null) {
            return $select($nextlimit);
        }

        $now ??= microtime(true);
        try {
            $fp = fopen($sharedFile, 'c+');
            if (!flock($fp, LOCK_EX)) {
                throw new RuntimeException('failed to lock file'); // @codeCoverageIgnore
            }

            $cache = json_decode(stream_get_contents($fp), true);
            $last  = $cache['last'] ?? 0;
            $jobs  = $cache['jobs'] ?? [];
            $next  = $cache['next'] ?? false;

            // do reselect if has next and no job
            if ($next && empty($jobs)) {
                // through
            }
            // return cache if within expiration
            elseif (($now - $last) < $waittime) {
                // randomize jobs for stuck
                uasort($jobs, fn($a, $b) => -(($a['priority'] ?? null) <=> ($b['priority'] ?? null)) ?: rand(-1, 1));
                return $jobs;
            }

            $jobs = $select($nextlimit);
            fseek($fp, 0);
            ftruncate($fp, 0);
            fwrite($fp, json_encode([
                'last' => $now,
                'jobs' => $jobs,
                'next' => count($jobs) === $nextlimit,
            ]));
            fflush($fp);

            return $jobs;
        }
        finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    protected function unshareJob(?string $sharedFile, string $job_id): ?array
    {
        if ($sharedFile === null) {
            return null;
        }

        try {
            $fp = fopen($sharedFile, 'c+');
            if (!flock($fp, LOCK_EX)) {
                throw new RuntimeException('failed to lock file'); // @codeCoverageIgnore
            }

            $cache  = json_decode(stream_get_contents($fp), true);
            $result = $cache['jobs'][$job_id] ?? null;
            unset($cache['jobs'][$job_id]);

            fseek($fp, 0);
            ftruncate($fp, 0);
            fwrite($fp, json_encode($cache));
            fflush($fp);

            return $result;
        }
        finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    protected function waitTime(?float $starttime, float $waittime, ?float $now = null): float
    {
        if ($starttime === null) {
            return $waittime;
        }

        $now  ??= microtime(true);
        $span = $now - $starttime;
        $tick = ceil($span / $waittime);
        $next = $starttime + $tick * $waittime;

        return $next - $now;
    }

    /**
     * ping to host
     *
     * returns:
     * - null: can't resolve the name.
     * - true: pong from the host
     * - false: no response from the host
     *
     * @param string $host
     * @param int $timeout
     * @return ?bool
     */
    protected function ping(string $host, int $timeout): ?bool
    {
        $ping = [
            "/"  => "ping -c 1 -W $timeout %s 2>/dev/null",
            "\\" => "ping -n 1 -w $timeout %s 2>nul",
        ];
        exec(sprintf($ping[DIRECTORY_SEPARATOR], $host), $stdout, $rc);

        if ($rc == 0) {
            return true;
        }
        else {
            return filter_var($host, FILTER_VALIDATE_IP) ? false : null;
        }
    }

    protected function cancel(?string $job_id = null, ?string $contents = null): int
    {
        UnsupportedException::throw("cancel is not supported");
    }
}
