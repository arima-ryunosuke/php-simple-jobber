<?php
# testing many-to-many (multi worker and multi client)
use Symfony\Component\Process\Process;

require_once __DIR__ . '/../vendor/autoload.php';

$DAEMON = 5;   # worker count
$CLIENT = 13;  # client count
$STRESS = 2;   # stress count
$TOTAL  = 257; # request count

$DRIVER = $argv[1];
$MODE   = $argv[2];

if ($MODE === "fork") {
    $DAEMON = 1;
}

$API = __DIR__ . "/api.php";

# clear queue
$process = new Process([PHP_BINARY, $API, $DRIVER, "clear"]);
$process->run();
if ($process->getExitCode()) {
    exit("clear failed\n");
}

$processes = [];

# ready worker
$workers = [];
for ($i = 0; $i < $DAEMON; $i++) {
    $process = new Process([PHP_BINARY, $API, $DRIVER, "worker:$MODE"]);
    $process->start();
    $processes[] = $workers[] = $process;
}

# ready stress
for ($i = 0; $i < $STRESS; $i++) {
    $process = new Process([PHP_BINARY, "-r", "while(true);"]);
    $process->start();
    $processes[] = $process;
}

# requests
foreach (array_chunk(range(1, $TOTAL - 1), $CLIENT) as $chunk) {
    $clients = array_map(fn($i) => new Process([PHP_BINARY, __DIR__ . "/api.php", $DRIVER, "client", $i, "1", "0"]), $chunk);
    array_map(fn($client) => $client->start(), $clients);
    array_map(fn($client) => $client->wait(), $clients);
}
$process = new Process([PHP_BINARY, __DIR__ . "/api.php", $DRIVER, "client", "finish", "0", "1"]);
$process->run();

# wait for finish request
while (true) {
    foreach ($workers as $worker) {
        if (strpos($worker->getOutput(), 'finish') !== false) {
            break 2;
        }
        $worker->getStatus();
    }
    usleep(50 * 1000);
}

# stop process
foreach ($processes as $process) {
    $process->stop();
}

# stat worker
$results = [];
$outputs = [
    'size'  => 0,
    'count' => 0,
];
if ($MODE === "fork") {
    foreach ($workers as $worker) {
        $output      = $worker->getOutput();
        $errorOutput = $worker->getErrorOutput();

        $outputs['size']  += strlen($output);
        $outputs['count'] += substr_count($output, "\n");

        preg_match_all('#\\[(?<pid>\\d+)\\](?<state>job|done|fail|finish)#', $errorOutput, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $name           = "$DRIVER-" . $match['pid'];
            $results[$name] ??= [
                'job'    => 0,
                'done'   => 0,
                'fail'   => 0,
                'finish' => 0,
            ];
            $results[$name][$match['state']]++;
        }

    }
}
else {
    foreach ($workers as $i => $worker) {
        $output      = $worker->getOutput();
        $errorOutput = $worker->getErrorOutput();

        $outputs['size']  += strlen($output);
        $outputs['count'] += substr_count($output, "\n");

        $result = [
            'job'    => 0,
            'done'   => 0,
            'fail'   => 0,
            'finish' => 0,
        ];
        preg_match_all('#\\[(?<pid>\\d+)\\](?<state>job|done|fail|finish)#', $errorOutput, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $result[$match['state']]++;
        }
        $results["$DRIVER-" . ($i + 1)] = $result;
    }
}

$results["total"] = [
    'job'    => array_sum(array_column($results, 'job')),
    'done'   => array_sum(array_column($results, 'done')),
    'fail'   => array_sum(array_column($results, 'fail')),
    'finish' => array_sum(array_column($results, 'finish')),
];

# print stat
printf("output size:%d, count:%d\n", $outputs['size'], $outputs['count']);
$max = max(array_map('strlen', array_keys($results))) + 1;
printf("%-{$max}s %6s %6s %6s %6s\n", "worker", ...array_keys(reset($results)));
foreach ($results as $name => $result) {
    printf("%-{$max}s %6s %6s %6s %6s\n", $name, ...array_values($result));
}
