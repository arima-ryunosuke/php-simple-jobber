<?php
$driver = require __DIR__ . '/driver.php';
$worker = new ryunosuke\hellowo\Worker(['driver' => $driver]);
$worker->fork(function (ryunosuke\hellowo\Message $message) {
    file_put_contents('/var/log/hellowo/receive.log', "$message\n", FILE_APPEND | LOCK_EX);

    // memory leak
    $string = str_repeat('x', 1024 * 1024);
    register_shutdown_function(function () use ($string) {
        // shutdown process
    });
}, 4, 8);
