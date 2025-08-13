<?php

namespace ryunosuke\hellowo\Driver;

use ReflectionMethod;
use ryunosuke\hellowo\API;
use ryunosuke\hellowo\ext\posix;

abstract class AbstractDriver extends API
{
    public static $driverMap = [
        'filesystem' => FileSystemDriver::class,
        'beanstalk'  => BeanstalkDriver::class,
        'gearman'    => GearmanDriver::class,
        'mysql'      => MySqlDriver::class,
        'pgsql'      => PostgreSqlDriver::class,
        'rabbitmq'   => RabbitMqDriver::class,
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

    public function __construct(string $description)
    {
        $this->description = $description;
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

    protected function daemonize(): void
    {
        posix::proc_cmdline(posix::proc_cmdline() . '#hellowo');
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
}
