hellowo
====

## Description

This package is simple job worker.

## Install

```json
{
    "require": {
        "ryunosuke/hellowo": "dev-master"
    }
}
```

This requires `pcntl` extension. Also, Windows only works minimally.

## Feature

### Driver

Driver features:

| feature                      | FileSystem      | Gearman         | Beanstalk       | MySql           | PostgreSql      | RabbitMQ        |
|------------------------------|-----------------|-----------------|-----------------|-----------------|-----------------|-----------------|
| simply                       | very high       | high            | high            | middle          | middle          | low             |
| pull or push(*1)             | pull(&inotify)  | push            | push            | pull(&trigger)  | pull(&pub/sub)  | push            |
| multi worker server          | optional(*2)    | yes             | yes             | yes             | yes             | yes             |
| not lost to sudden death(*3) | yes (ttr)       | no              | yes (ttr)       | yes (kill)      | yes (keepalive) | yes (heartbeat) |
| priority job                 | yes             | yes             | yes             | yes             | yes             | yes             |
| delay job                    | yes             | no              | yes             | yes             | yes             | optional(*4)    |
| managed retry                | no              | no              | no              | no              | no              | yes             |
| unmanaged retry limit        | yes             | no              | no              | yes             | yes             | no              |
| clustering                   | no              | no              | no              | optional(*5)    | optional(*5)    | yes             |

- *1 push is almost real-time, but pull has time lag due to polling
- *2 e.g. NFS
- *3 Except for FileSystem, TCP keepalive can be enabled to some extent
- *4 e.g. rabbitmq-delayed-message-exchange, Deadletter exchange
- *5 e.g. Replication, Fabric, NDB

### Worker

The recommended process manager is systemd.
Also, the basic operation is in series. If you want to run multiple jobs in parallel, you need to launch multiple processes.

SIGALRM is used to implement the timeout, so it cannot be used by the user.
When it receives an SIGTERM or SIGINT, it waits for the currently running job until stopping it. Therefore, in some cases, it may take a long time to stop (see TimeoutStopSec of systemd).

Default logging, operation log is written to STDOUT. php error log is written to STDERR.
Operation log can be changed by overriding the `logger` option.
php error log uses system default. This can be changed by php.ini or ini_set.

### Client

Client is a simple class from which only the request part of the driver is extracted.
You can use any client to send jobs without using this class.

- e.g. filesystem: `touch /path/to/job.txt`
- e.g. mysql: `INSERT INTO jobs(message) VALUES("foo")`

## Demo

- Driver: mysql
- Parallel: 4
- Log: /var/log/hellowo

require root.

### ready

```bash
sudo su -
RUNSCRIPT=/path/to/example.php
```

### ready worker

```bash
cat << 'EOS' > $RUNSCRIPT
<?php
require_once __DIR__ . '/vendor/autoload.php';
$worker = new ryunosuke\hellowo\Worker([
    'work'   => function (ryunosuke\hellowo\Message $message) {
        file_put_contents('/var/log/hellowo/receive.log', "$message\n", FILE_APPEND | LOCK_EX);
    },
    'driver' => new ryunosuke\hellowo\Driver\MySqlDriver([
        'starttime' => strtotime('2000-01-01 00:00:00') + getenv('SYSTEMD_SERVICE_ID') * 4,
        'waittime'  => 30.0,
        'waitmode'  => 'sql',
        'transport' => [
            'host'     => '127.0.0.1',
            'user'     => 'root',
            'password' => 'password',
            'database' => 'test',
        ],
        // job table name
        'table'    => 't_job',
    ]),
]);
$worker->start();
EOS
```

### ready systemd

```bash
cat << EOS > /etc/systemd/system/example@.service
[Unit]
After=network.target
PartOf=example.target

[Service]
Type=simple
Environment=SYSTEMD_SERVICE_ID=%i
ExecStartPre=/bin/mkdir -p /var/log/hellowo
ExecStart=/bin/sh -c 'exec /usr/bin/php $RUNSCRIPT 1>/var/log/hellowo/stdout-%i.log 2>/var/log/hellowo/stderr-%i.log'
TimeoutStopSec=90s
Restart=always

[Install]
EOS

cat << EOS > /etc/systemd/system/example.target
[Unit]
Wants=example@1.service
Wants=example@2.service
Wants=example@3.service
Wants=example@4.service

[Install]
WantedBy=multi-user.target
EOS

systemctl daemon-reload
systemctl restart example.target
systemctl status example@*
```

### send data

```bash
cat << EOS | mysql -h 127.0.0.1 -u root test
INSERT INTO t_job (message)
WITH RECURSIVE seq (n) AS
(
  SELECT 1
  UNION ALL
  SELECT n + 1 FROM seq WHERE n < 100
)
SELECT CONCAT("data-", n) FROM seq;
EOS
```

### output log

```bash
cat /var/log/hellowo/stdout-*.log
[Y-m-dTH:i:s.v][1045984] ...
[Y-m-dTH:i:s.v][1045984] ...
[Y-m-dTH:i:s.v][1045984] ...

cat -n /var/log/hellowo/receive.log
data-8
data-17
data-15
...
data-98
data-96
data-99
```

## License

MIT

## Release

Versioning is romantic versioning(no semantic versioning).

- major: large BC break. e.g. change architecture, package, class etc
- minor: small BC break. e.g. change arguments, return type etc
- patch: no BC break. e.g. fix bug, add optional arguments, code format etc

### x.y.z

- RabbitMQ の廃止
  - AMQP ならば特化した専用のパッケージを使った方が良い
- API の除去
  - protected で不要なメソッドを隠す意図の設計だったが足枷になってきている

### 1.1.11

- [change] ブランチ切り替えがしんどいので docker-compose の変更をバックポート
- [feature] standup イベントを追加
- [fixbug] readonly 状態では SELECT FOR UPDATE を投げられない
- [fixbug] エラー時は正常終了ではなく異常終了で systemd の再起動を促す

### 1.1.10

- [feature] breather イベントを追加
- [change] rework Gearman
- [feature] cancel 実装
- [refactor] 例外の構造変更と UnsupportedException を追加
- [fixbug] sha1 は無駄だし一意ではない

### 1.1.9

- [feature] prepare は1回で十分
- [fixbug] systemd で起動しない不具合
- [fixbug] EchoLogger の引数漏れ

### 1.1.8

- [feature] EchoLogger にレベルフィルタを実装
- [feature] ジョブ候補をファイルキャッシュする機能
- [feature] ジョブを分散させるために starttime を追加
- [fixbug] 無駄な行を取りすぎている
- [fixbug] active 側で isStandby が高頻度で呼ばれている

### 1.1.7

- [fixbug] 並列数が1になることがある不具合

### 1.1.6

- [feature] start 時に work を与えられる機能

### 1.1.5

- [feature] PostgreSqlDriver
- [fixbug] 初回実行時に setup されない不具合
- [fixbug] 一部のドライバーにミリ秒を与えるとエラーになる
- [fixbug] heartbeat 秒応答がないホストに対して heartbeat 秒 ping を待機している
- [feature] delay がある場合は notify しても無駄
- [feature] MySql のミリ秒対応

### 1.1.4

- [fixbug] USR1 以外でも async をキャンセルしていた
- [fixbug] select と sleep で使用されるインデックスが異なる可能性がある

### 1.1.3

- [feature] JSON を組み込みで実装
- [refactor] API の整理
- [refactor] ext のパスを変更
- [refactor] Listener の階層を Logger と合わせる

### 1.1.2

- [feature] リトライ回数を実装
- [fixbug] standby 状態で setup して死ぬ不具合を修正

### 1.1.1

- [feature] added restart trigger

### 1.1.0

- [*change] select+done+retry -> generator
- [change] log format
- [fixbug] no keeps connecting on server gone away

### 1.0.1

- [feature] check writable mode
- [fixbug] transaction may not be closed
- [fixbug] fix miss (PROCEDURE -> FUNCTION)

### 1.0.0

- [feature] added logger logs when Throwable
- [refactor] changed nullable to notnull

### 0.2.0

- [fixbug] fixed "Commands out of sync" when receive USR1
- [fixbug] deleted IF EXISTS from mysql driver
- [feature] added listen cycle event

### 0.1.0

- [change] move notify to notifyLocal

### 0.0.0

- publish
