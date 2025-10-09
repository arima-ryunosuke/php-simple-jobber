<?php
$driver = require __DIR__ . '/driver.php';
$client = new ryunosuke\hellowo\Client(['driver' => $driver]);
$client->transactional(function (int $count) use ($client) {
    $client->sendBulk(array_map(fn($n) => sprintf('data-%04d', $n), range(1, $count)));
}, $argv[1] ?? 100);
