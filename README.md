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

| feature                      | FileSystem      | Gearman         | Beanstalk       | MySql           | PostgreSql      |
|------------------------------|-----------------|-----------------|-----------------|-----------------|-----------------|
| simply                       | very high       | high            | high            | middle          | middle          |
| pull or push(*1)             | pull(&inotify)  | push            | push            | pull(&trigger)  | pull(&pub/sub)  |
| multi worker server          | optional(*2)    | yes             | yes             | yes             | yes             |
| not lost to sudden death(*3) | yes (ttr)       | no              | yes (ttr)       | yes (kill)      | yes (keepalive) |
| priority job                 | yes             | yes             | yes             | yes             | yes             |
| delay job                    | yes             | no              | yes             | yes             | yes             |
| managed retry                | no              | no              | no              | no              | no              |
| unmanaged retry limit        | yes             | no              | no              | yes             | yes             |
| clustering                   | no              | no              | no              | optional(*4)    | optional(*4)    |

- *1 push is almost real-time, but pull has time lag due to polling
- *2 e.g. NFS
- *3 Except for FileSystem, TCP keepalive can be enabled to some extent
- *4 e.g. Replication, Fabric, NDB

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
You can use `send` or `sendBulk` for add job.

## Demo

- Driver: mysql
- Parallel: 4-8
- Log: /var/log/hellowo

```bash
sudo sh demo/install.sh
sudo systemctl restart hellowo
php demo/client.php 100
```

### execution log

```bash
cat /var/log/hellowo/stdout.log
[Y-m-dTH:i:s.v][1045984] ...
[Y-m-dTH:i:s.v][1045984] ...
[Y-m-dTH:i:s.v][1045984] ...
```

### receive data

```bash
cat -n /var/log/hellowo/receive.log
     1  data-0017
     2  data-0001
     3  data-0005
     4  data-0021
     5  data-0010
    ...
    95  data-0087
    96  data-0092
    97  data-0089
    98  data-0098
    99  data-0100
   100  data-0099
```

## License

MIT

## Release

Versioning is romantic versioning(no semantic versioning).

- major: large BC break. e.g. change architecture, package, class etc
- minor: small BC break. e.g. change arguments, return type etc
- patch: no BC break. e.g. fix bug, add optional arguments, code format etc

### x.y.z

- API の除去
  - protected で不要なメソッドを隠す意図の設計だったが足枷になってきている

### 1.2.5

- [feature] driver にも logger を持たせる
- [feature] ジョブが連なっているときは wait しない
- [refactor] posix::pgrep をリライト
- [refactor] notifyLocal とプロセス名の関係をリワーク
- [change] interpolate で階層のないリスト配列を特別扱いする
- [change] psr-3 的に exception というキーに例外オブジェクト以外を与えてはならない
- [feature] restart を fork でも活かす

### 1.2.4

- [feature] fork モードを仮実装
- [feature] transaction を外だし
- [feature] register_shutdown_function を模倣する機能
- [feature] client/worker が共通コネクションの driver の遅延接続
- [feature] continue/breather の正規化
- [feature] restart の引数に workload(処理回数) を追加
- [feature] ログ回りの改善

### 1.2.3

- Merge tag 'v1.1.12'

### 1.2.2

- Merge tag 'v1.1.11'

### 1.2.1

- [fixbug] client で文字列以外を send するときに IDE エラーが出る

### 1.2.0

- [fixbug] gearman に未来 job を登録するとその間無限ループする
- [*feature] job 側にも timeout を持たせる
- [*feature] delay に予定時刻を入れられる機能
- [*feature] 失敗したジョブを保存する機能
- [feature] sendBulk を追加
- [*change] sendJson 廃止
- [*change] notify は Client の責務とする
- [*change] job データは json で包む
- [*change] wait 系のデフォルト waittime を 10 に変更
- [*change] drop deprecated
- [tests] 意味の分からないコードがあったので除去

### 1.1.12

- [feature] 絶え間なくジョブが実行され続けたら警告を出す
- [feature] 結果の如何にかかわらず job を検知して終了した場合のイベント finish を追加

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
