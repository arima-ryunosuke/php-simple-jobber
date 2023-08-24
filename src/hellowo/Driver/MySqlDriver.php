<?php

namespace ryunosuke\hellowo\Driver;

use Exception;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use ryunosuke\hellowo\Message;
use Throwable;

/**
 * architecture:
 * FOR UPDATE during job running.
 * SKIP LOCKED provides exclusive control of running jobs.
 * NOTIFY can be sent to waiting processes for immediate execution.
 * PROCESSLIST can monitor members and disconnect unresponsive servers.
 */
class MySqlDriver extends AbstractDriver
{
    public static function isEnabled(): bool
    {
        return extension_loaded('mysqli');
    }

    protected static function normalizeParams(array $params): array
    {
        $parts = explode('.', trim($params['path'] ?? '', '/'), 2);
        return [
            'database' => $parts[0] ?? null,
            'table'    => $parts[1] ?? null,
        ];
    }

    private array  $transport;
    private mysqli $connection;
    private string $table;

    private float  $waittime;
    private string $waitmode;
    private bool   $trigger;

    private int   $heartbeat;
    private float $heartbeatTimer;

    public function __construct(array $options)
    {
        $options = self::normalizeOptions($options, [
            // mysqli instance or mysqli DSN
            'transport' => [
                'host'     => '127.0.0.1',
                'port'     => 3306,
                'username' => null,
                'password' => null,
            ],
            // db and table
            'database'  => null,
            'table'     => 'hellowo',
            // one cycle wait time
            'waittime'  => 10.0,
            // sql: use SELECT SLEEP(), php: call usleep
            'waitmode'  => 'sql',
            // create awake trigger after insert (sql mode only)
            'trigger'   => true,
            // kills sleeping connections of different hosts for sudden death. requires PROCESS privileges
            'heartbeat' => 0,
        ]);

        // connection
        $transport = $options['transport'];
        if (is_array($transport)) {
            $transport['hostname'] = $transport['host'];     // for compatible php7/8
            $transport['user']     = $transport['username']; // for compatible php7/8
            $transport['database'] = $options['database'];   // for compatible php7/8
            $transport['socket']   = null;                   // for compatible php7/8
            $this->transport       = $transport;
            $transport             = new mysqli(...self::normalizeArguments([mysqli::class, '__construct'], $transport));
        }
        $this->connection = $transport;
        $this->table      = $options['table'];

        $this->waittime = $options['waittime'];
        $this->waitmode = $options['waitmode'];
        $this->trigger  = $options['trigger'] && $options['waitmode'] === 'sql';

        $this->heartbeat      = $options['heartbeat'];
        $this->heartbeatTimer = microtime(true) + $this->heartbeat;

        parent::__construct("mysql {$this->connection->server_info}/{$options['table']}");
    }

    protected function setup(bool $forcibly = false): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $this->connection->query("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");

        if ($forcibly) {
            $this->connection->query("DROP TRIGGER IF EXISTS {$this->table}_awake_trigger");
            $this->connection->query("DROP PROCEDURE IF EXISTS {$this->table}_awake");
            $this->connection->query("DROP TABLE IF EXISTS {$this->table}");
        }

        // table
        $this->connection->query(<<<SQL
            CREATE TABLE IF NOT EXISTS {$this->table}(
                job_id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                message   LONGBLOB NOT NULL,
                priority  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                start_at  DATETIME NOT NULL DEFAULT NOW(),
                PRIMARY KEY (job_id),
                INDEX IDX_SELECT (start_at, priority)
            )
            SQL
        );

        if ($this->waitmode === 'sql') {
            // function
            // separate statement. because "CREATE FUNCTION IF NOT EXISTS" is not supported mysql version
            if (!$this->connection->query("SELECT 1 FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = '{$this->table}_awake'")->num_rows) {
                $this->connection->query(<<<SQL
                    CREATE FUNCTION {$this->table}_awake(kill_count BIGINT) RETURNS BIGINT
                    LANGUAGE SQL NOT DETERMINISTIC READS SQL DATA
                    BEGIN
                        DECLARE result    BIGINT DEFAULT 0;
                        DECLARE processId BIGINT UNSIGNED;
                        DECLARE done      INT DEFAULT FALSE;
                        DECLARE processes CURSOR FOR
                            SELECT ID
                            FROM information_schema.PROCESSLIST
                            WHERE DB      = DATABASE()
                                AND STATE = "User sleep"
                                AND INFO  LIKE '/*by {$this->table}*/%'
                            ORDER BY TIME DESC
                            LIMIT kill_count
                        ;
                        DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
                        
                        OPEN processes;
                            read_loop: LOOP
                                FETCH processes INTO processId;
                                
                                IF done THEN
                                    LEAVE read_loop;
                                END IF;
                                
                                KILL QUERY processId;
                                SET result = result + 1;
                            END LOOP;
                        CLOSE processes;
                        
                        RETURN result;
                    END
                    SQL
                );
            }

            if ($this->trigger) {
                // trigger
                // separate statement. because "CREATE TRIGGER IF NOT EXISTS" also causes metadata lock
                if (!$this->connection->query("SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = '{$this->table}_awake_trigger'")->num_rows) {
                    $this->connection->query(<<<SQL
                        CREATE TRIGGER {$this->table}_awake_trigger AFTER INSERT ON {$this->table} FOR EACH ROW
                        DO {$this->table}_awake(1)
                        SQL
                    );
                }
            }
        }
    }

    protected function select(): ?Message
    {
        $this->connection->begin_transaction();
        try {
            // mysql's lock is index lock. therefore must be locked by primary key
            $jobs = $this->execute(
                "SELECT * FROM {$this->table} WHERE job_id = (SELECT job_id FROM {$this->table} WHERE start_at <= NOW() ORDER BY priority DESC LIMIT 1) FOR UPDATE SKIP LOCKED",
            );

            if ($jobs) {
                $job = $jobs[0];
                return new Message($job, $job['job_id'], $job['message']);
            }
            $this->connection->commit();
        }
        catch (Throwable $ex) {
            $this->connection->rollback();
            throw $ex;
        }

        $this->sleep();
        $this->recover();
        return null;
    }

    protected function done(Message $message): void
    {
        $this->execute("DELETE FROM {$this->table} WHERE job_id = ?", [$message->getId()]);
        $this->connection->commit();
    }

    protected function retry(Message $message, float $time): void
    {
        $this->execute("UPDATE {$this->table} SET start_at = NOW() + INTERVAL ? SECOND WHERE job_id = ?", [$time, $message->getId()]);
        $this->connection->commit();
    }

    protected function error(Exception $e): bool
    {
        return !$this->execute('SELECT 1');
    }

    protected function close(): void
    {
        $this->connection->close();
        unset($this->connection);

        gc_collect_cycles();
    }

    protected function send(string $contents, ?int $priority = null, ?float $delay = null): ?string
    {
        $priority = $priority ?? 32767;
        $delay    = $delay ?? 0;
        $this->execute(
            "INSERT INTO {$this->table} SET message = ?, priority = ?, start_at = NOW() + INTERVAL ? SECOND",
            [$contents, $priority, $delay],
        );

        if (!$this->trigger) {
            $this->notify();
        }

        return $this->connection->insert_id;
    }

    protected function notify(int $count = 1): int
    {
        if ($this->waitmode === 'sql') {
            return $this->execute("SELECT {$this->table}_awake(?) AS c", [$count])[0]['c'];
        }
        elseif ($this->waitmode === 'php') {
            return count(parent::notifyLocal($count));
        }
    }

    protected function clear(): int
    {
        return $this->execute("DELETE FROM {$this->table}");
    }

    protected function sleep(): void
    {
        if ($this->waitmode === 'sql') {
            // combination technique async select sleep and select syscall
            // - select sleep:   able to kill, but unable to receive signal
            // - select syscall: unable to kill, but able to receive signal
            $this->connection->query("/*by {$this->table}*/ SELECT IF(EXISTS(SELECT * FROM {$this->table} WHERE start_at <= NOW() FOR UPDATE SKIP LOCKED), 1, SLEEP({$this->waittime})) AS c", MYSQLI_ASYNC);

            do {
                $read = $error = $reject = [$this->connection];
                if (@mysqli::poll($read, $error, $reject, 1) === false) {
                    // @codeCoverageIgnoreStart
                    if (isset($this->transport)) {
                        // killed by other connection for USR1
                        $mysqli = new mysqli(...self::normalizeArguments([mysqli::class, '__construct'], $this->transport));
                        $mysqli->prepare("KILL QUERY {$this->connection->thread_id}")->execute();
                        $mysqli->close();
                        $this->connection->reap_async_query();
                    }
                    return;
                    // @codeCoverageIgnoreEnd
                }
                foreach ($read as $mysqli) {
                    $result = $mysqli->reap_async_query();
                    if ($result === false) {
                        throw new \RuntimeException("mysqli::reap_async_query returned false"); // @codeCoverageIgnore
                    }
                    $all = $result->fetch_all(MYSQLI_ASSOC);
                    $result->free();

                    // https://dev.mysql.com/doc/refman/5.6/en/miscellaneous-functions.html#function_sleep
                    // > SLEEP() is interrupted, it returns 1
                    if ($this->trigger && $all[0]['c']) {
                        // insert -> trigger -> cycle is may be taken down too early
                        usleep(60 * 1000);
                    }
                }
                assert(!$error);
                assert(!$reject);
            } while (!$read);
        }
        elseif ($this->waitmode === 'php') {
            usleep($this->waittime * 1000 * 1000);
        }
    }

    protected function recover(): array
    {
        if (!$this->heartbeat) {
            return [];
        }

        if (microtime(true) < $this->heartbeatTimer) {
            return [];
        }
        $this->heartbeatTimer = microtime(true) + $this->heartbeat;

        $result = [];
        foreach ($this->processlist() as $process) {
            if ($process['TIME'] >= $this->heartbeat && $process['COMMAND'] === 'Sleep') {
                [$host] = explode(':', $process['HOST']);
                if ($this->ping($host, $this->heartbeat) === false) {
                    $this->execute("KILL ?", [$process['ID']]);
                    $result[$process['ID']] = $process;
                }
            }
        }
        return $result;
    }

    protected function processlist(): array
    {
        return $this->execute(
            "SELECT * FROM information_schema.PROCESSLIST WHERE DB = DATABASE() AND ID <> CONNECTION_ID() AND USER = SUBSTRING_INDEX(USER(), '@', 1)",
        );
    }

    protected function execute(string $query, array $bind = [])
    {
        $statement = $this->connection->prepare($query);
        if ($bind) {
            $statement->bind_param(str_repeat('s', count($bind)), ...array_values($bind));
        }

        // rety. because query may be killed when also not SLEEP at race condition
        $retry = 3;
        for ($i = 1; $i <= $retry; $i++) {
            try {
                $statement->execute();
                $result = $statement->get_result();
                if ($result instanceof mysqli_result) {
                    $all = $result->fetch_all(MYSQLI_ASSOC);
                    $result->free_result();
                    return $all;
                }
                return $statement->affected_rows;
            }
            catch (mysqli_sql_exception $e) {
                if ($i < $retry && $e->getCode() === 1317 && strpos($e->getMessage(), "Query execution was interrupted") !== false) {
                    continue;
                }

                throw $e;
            }
            finally {
                $statement->free_result();
            }
        }
    }
}
