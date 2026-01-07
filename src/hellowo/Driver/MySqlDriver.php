<?php

namespace ryunosuke\hellowo\Driver;

use Exception;
use Generator;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use ryunosuke\hellowo\Exception\DriverException;
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

    private ?float  $starttime;
    private float   $waittime;
    private string  $waitmode;
    private string  $deadmode;
    private bool    $trigger;
    private ?string $sharedFile;

    private int   $heartbeat;
    private float $heartbeatTimer;

    private bool  $syscalled  = false;
    private array $statements = [];

    public function __construct(array $options)
    {
        $options = self::normalizeOptions($options, [
            // mysqli instance or mysqli DSN
            'transport'  => [
                'host'     => '127.0.0.1',
                'port'     => 3306,
                'username' => null,
                'password' => null,
            ],
            // db and table
            'database'   => null,
            'table'      => 'hellowo',
            // null: wait waittime simply, int: wait until starttime+waittime
            'starttime'  => null,
            // one cycle wait time
            'waittime'   => 10.0,
            // sql: use SELECT SLEEP(), php: call usleep
            'waitmode'   => 'sql',
            // table: insert error table, column: update error column
            'deadmode'   => '',
            // sharing job filename
            'sharedFile' => null,
            // create awake trigger after insert (sql mode only)
            'trigger'    => true,
            // kills sleeping connections of different hosts for sudden death. requires PROCESS privileges
            'heartbeat'  => 0,
        ]);

        if (is_array($options['transport'])) {
            $options['transport']['hostname'] = $options['transport']['host'];     // for compatible php7/8
            $options['transport']['user']     = $options['transport']['username']; // for compatible php7/8
            $options['transport']['socket']   = null;                              // for compatible php7/8

            $this->transport = $options['transport'] + ['database' => $options['database']];
            parent::__construct("mysql {$this->transport['hostname']}/{$options['table']}", $options['logger'] ?? null);
        }
        else {
            $this->connection = $options['transport'];
            parent::__construct("mysql {$this->connection->server_info}/{$options['table']}", $options['logger'] ?? null);
        }
        $this->table = $options['table'];

        $this->starttime  = $options['starttime'];
        $this->waittime   = $options['waittime'];
        $this->waitmode   = $options['waitmode'];
        $this->trigger    = $options['trigger'] && $options['waitmode'] === 'sql';
        $this->deadmode   = $options['deadmode'];
        $this->sharedFile = $options['sharedFile'];

        $this->heartbeat      = $options['heartbeat'];
        $this->heartbeatTimer = microtime(true) + $this->heartbeat;
    }

    protected function getConnection(): mysqli
    {
        if (!isset($this->connection)) {
            $this->logger->info('{event}: {host}:{port}/{database}#{table}', ['event' => 'connect', 'host' => $this->transport['hostname'], 'port' => $this->transport['port'], 'database' => $this->transport['database'], 'table' => $this->table]);
            $this->connection = new mysqli(...self::normalizeArguments([mysqli::class, '__construct'], $this->transport));
            if ($this->connection->connect_errno) {
                DriverException::throw($this->connection->connect_error, $this->connection->connect_errno); // @codeCoverageIgnore
            }
        }

        return $this->connection;
    }

    protected function setup(bool $forcibly = false): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $this->getConnection()->query("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");

        if ($forcibly) {
            $this->getConnection()->query("DROP TRIGGER IF EXISTS {$this->table}_awake_trigger");
            $this->getConnection()->query("DROP FUNCTION IF EXISTS {$this->table}_awake");
            $this->getConnection()->query("DROP TABLE IF EXISTS {$this->table}");
            $this->getConnection()->query("DROP TABLE IF EXISTS {$this->table}_dead");
        }

        // table
        $this->getConnection()->query(<<<SQL
            CREATE TABLE IF NOT EXISTS {$this->table}(
                job_id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                job_data  JSON NOT NULL,
                priority  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                start_at  DATETIME(3) NOT NULL DEFAULT NOW(3),
                error     LONGTEXT DEFAULT NULL,
                PRIMARY KEY (job_id),
                INDEX IDX_SELECT (start_at, priority)
            )
            SQL
        );
        if ($this->deadmode === 'table') {
            $this->getConnection()->query(<<<SQL
                CREATE TABLE IF NOT EXISTS {$this->table}_dead(
                    job_id    BIGINT UNSIGNED NOT NULL,
                    message   JSON NOT NULL,
                    priority  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    start_at  DATETIME(3) NOT NULL DEFAULT NOW(3),
                    error     LONGTEXT DEFAULT NULL,
                    PRIMARY KEY (job_id)
                )
                SQL
            );
        }

        if ($this->waitmode === 'sql') {
            // function
            // separate statement. because "CREATE FUNCTION IF NOT EXISTS" is not supported mysql version
            if (!$this->getConnection()->query("SELECT 1 FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = '{$this->table}_awake'")->num_rows) {
                $this->getConnection()->query(<<<SQL
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
                if (!$this->getConnection()->query("SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = '{$this->table}_awake_trigger'")->num_rows) {
                    $this->getConnection()->query(<<<SQL
                        CREATE TRIGGER {$this->table}_awake_trigger AFTER INSERT ON {$this->table} FOR EACH ROW
                        DO {$this->table}_awake(1)
                        SQL
                    );
                }
            }
        }

        if ($this->sharedFile !== null) {
            @mkdir(dirname($this->sharedFile), 0755, true);
        }
    }

    protected function isStandby(): bool
    {
        try {
            // job_id is unsigned int
            $this->execute("DELETE FROM {$this->table} WHERE job_id = ?", [-1]);
            return false;
        }
        catch (DriverException $e) {
            if ($e->getCode() === 2006) {
                throw $e;
            }
            return true;
        }
    }

    protected function select(): Generator
    {
        $jobs = $this->shareJob($this->sharedFile, $this->waittime, 256, fn($limit) => array_column($this->execute($this->selectJob(['job_id', 'priority'], $limit)), null, 'job_id'));

        foreach ($jobs as $job_id => $job) {
            $this->begin();
            try {
                $row = $this->execute("SELECT * FROM {$this->table} WHERE job_id = ? FOR UPDATE SKIP LOCKED", [$job_id])[0] ?? null;

                if ($row === null) {
                    $this->unshareJob($this->sharedFile, $job_id);
                    $this->rollback();
                    continue;
                }

                $job    = $this->decode($row['job_data']);
                $result = yield new Message($job_id, $job['contents'], $job['retry'], $job['timeout']);
                if ($result === null) {
                    $this->execute("DELETE FROM {$this->table} WHERE job_id = ?", [$job_id]);
                }
                elseif (is_int($result) || is_float($result)) {
                    $job['retry']++;
                    $this->execute("UPDATE {$this->table} SET job_data = ?, start_at = NOW(3) + INTERVAL ? SECOND WHERE job_id = ?", [$this->encode($job), $result, $job_id]);
                }
                else {
                    if ($this->deadmode === 'table') {
                        $this->execute("INSERT INTO {$this->table}_dead SELECT job_id, job_data, priority, start_at, ? FROM {$this->table} WHERE job_id = ?", ["$result", $job_id]);
                        $this->execute("DELETE FROM {$this->table} WHERE job_id = ?", [$job_id]);
                    }
                    elseif ($this->deadmode === 'column') {
                        $this->execute("UPDATE {$this->table} SET error = ? WHERE job_id = ?", ["$result", $job_id]);
                    }
                    else {
                        $this->execute("DELETE FROM {$this->table} WHERE job_id = ?", [$job_id]);
                    }
                }
                $this->unshareJob($this->sharedFile, $job_id);
                $this->commit();
                return;
            }
            catch (Throwable $ex) {
                $this->rollback();
                throw $ex;
            }
        }

        $this->sleep();
        $this->recover();
    }

    protected function error(Exception $e): bool
    {
        return $e instanceof DriverException;
    }

    protected function close(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
            unset($this->connection);
        }

        gc_collect_cycles();
    }

    protected function send(string $contents, ?int $priority = null, $time = null, int $timeout = 0): ?string
    {
        $priority = $priority ?? 32767;
        $this->execute(
            "INSERT INTO {$this->table} SET job_data = ?, priority = ?, start_at = NOW(3) + INTERVAL ? SECOND",
            [$this->encode(['contents' => $contents, 'timeout' => $timeout]), $priority, $this->getDelay($time)],
        );

        return $this->getConnection()->insert_id;
    }

    protected function notify(int $count = 1): int
    {
        if ($this->waitmode === 'sql') {
            return $this->execute("SELECT {$this->table}_awake(?) AS c", [$count])[0]['c'];
        }
        elseif ($this->waitmode === 'php') {
            return count($this->notifyLocal($count));
        }
    }

    protected function cancel(?string $job_id = null, ?string $contents = null): int
    {
        $this->begin();
        try {
            // cannot cancel items already in progress
            $where  = 'FALSE';
            $params = [];
            if ($job_id !== null) {
                $where    .= ' OR job_id = ?';
                $params[] = $job_id;
            }
            if ($contents !== null) {
                $where    .= ' OR job_data->>"$.contents" = ?';
                $params[] = $contents;
            }
            $job_ids = array_column($this->execute("SELECT job_id FROM {$this->table} WHERE error IS NULL AND ($where) FOR UPDATE SKIP LOCKED", $params, false), 'job_id');

            $count = 0;
            if ($job_ids) {
                $count = $this->execute("DELETE FROM {$this->table} WHERE job_id IN (" . implode(',', array_fill(0, count($job_ids), '?')) . ")", $job_ids, false);
            }

            $this->commit();
            return $count;
        }
        catch (Throwable $ex) {
            $this->rollback();
            throw $ex;
        }
    }

    protected function clear(): int
    {
        return $this->execute("DELETE FROM {$this->table}");
    }

    protected function sleep(): void
    {
        $waittime = $this->waitTime($this->starttime, $this->waittime);

        if ($this->waitmode === 'sql') {
            // combination technique async select sleep and select syscall
            // - select sleep:   able to kill, but unable to receive signal
            // - select syscall: unable to kill, but able to receive signal
            $this->getConnection()->query("/*by {$this->table}*/ SELECT IF(EXISTS({$this->selectJob()}), 1, SLEEP({$waittime})) AS c", MYSQLI_ASYNC);

            do {
                $read = $error = $reject = [$this->getConnection()];
                if (@mysqli::poll($read, $error, $reject, 1) === false) {
                    // @codeCoverageIgnoreStart
                    $this->syscalled = true;
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
            usleep(intval($waittime * 1000 * 1000));
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
                if ($this->ping($host, 10) === false) {
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

    protected function selectJob(array $column = ['*'], ?int $limit = null): string
    {
        $column = implode(', ', $column);

        if ($limit !== null) {
            $limit = "LIMIT $limit";
        }

        return "SELECT $column FROM {$this->table} WHERE start_at <= NOW(3) AND error IS NULL ORDER BY priority DESC, job_id ASC $limit FOR UPDATE SKIP LOCKED";
    }

    protected function execute(string $query, array $bind = [], bool $cachePrepare = true)
    {
        $this->logger->debug("{event}: {sql}({bind})", ['event' => 'execute', 'sql' => $query, 'bind' => $bind]);

        // poll called by other process for USR1
        if ($this->syscalled && isset($this->transport)) {
            $this->syscalled = false;
            $mysqli          = new mysqli(...self::normalizeArguments([mysqli::class, '__construct'], $this->transport));
            $mysqli->prepare("KILL QUERY {$this->getConnection()->thread_id}")->execute();
            $mysqli->close();
            $this->getConnection()->reap_async_query();
        }

        try {
            $statement = $this->statements[$query] ??= $this->getConnection()->prepare($query);
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

                    if (!$cachePrepare) {
                        $statement->close();
                        unset($this->statements[$query]);
                    }
                }
            }
        }
        catch (mysqli_sql_exception $e) {
            DriverException::throw($e->getMessage(), $e->getCode(), $e);
        }
    }

    protected function query(string $query)
    {
        return $this->getConnection()->query($query);
    }

    protected function begin()
    {
        $this->getConnection()->begin_transaction();
    }

    protected function commit()
    {
        $this->getConnection()->commit();
    }

    protected function rollback()
    {
        $this->getConnection()->rollback();
    }
}
