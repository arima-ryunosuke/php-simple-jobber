<?php
require_once __DIR__ . '/../vendor/autoload.php';
return new ryunosuke\hellowo\Driver\MySqlDriver([
    'transport'  => [
        'host'     => '127.0.0.1',
        'port'     => 23306,
        'username' => 'user',
        'password' => 'password',
    ],
    // job database.table name
    'database'   => 'hellowo',
    'table'      => 't_job',
    'waittime'   => 2.0,
    'waitmode'   => 'php',
    'sharedFile' => '/tmp/jobs.txt',
]);
