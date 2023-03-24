<?php
/**
 * @codeCoverageIgnore this is minimally emulation on windows for test
 * @noinspection PhpIgnoredClassAliasDeclaration
 */
namespace ryunosuke\hellowo\ext;

// This constant is only for property assignment dynamically(expression) and has no other meaning
foreach ([
    'GEARMAN_NO_JOBS'               => 35,
    'GEARMAN_ECHO_DATA_CORRUPTION'  => 36,
    'GEARMAN_NEED_WORKLOAD_FN'      => 37,
    'GEARMAN_PAUSE'                 => 38,
    'GEARMAN_UNKNOWN_STATE'         => 39,
    'GEARMAN_PTHREAD'               => 40,
    'GEARMAN_PIPE_EOF'              => 41,
    'GEARMAN_QUEUE_ERROR'           => 42,
    'GEARMAN_FLUSH_DATA'            => 43,
    'GEARMAN_SEND_BUFFER_TOO_SMALL' => 44,
    'GEARMAN_IGNORE_PACKET'         => 45,
    'GEARMAN_UNKNOWN_OPTION'        => 46,
    'GEARMAN_TIMEOUT'               => 47,
    'GEARMAN_MAX_RETURN'            => 49,
] as $name => $value) {
    define(__NAMESPACE__ . "\\$name", defined($name) ? constant($name) : $value);
}

/**
 * @codeCoverageIgnore this is minimally emulation on windows for test
 */
class gearman
{
    public const GEARMAN_NO_JOBS               = GEARMAN_NO_JOBS;
    public const GEARMAN_ECHO_DATA_CORRUPTION  = GEARMAN_ECHO_DATA_CORRUPTION;
    public const GEARMAN_NEED_WORKLOAD_FN      = GEARMAN_NEED_WORKLOAD_FN;
    public const GEARMAN_PAUSE                 = GEARMAN_PAUSE;
    public const GEARMAN_UNKNOWN_STATE         = GEARMAN_UNKNOWN_STATE;
    public const GEARMAN_PTHREAD               = GEARMAN_PTHREAD;
    public const GEARMAN_PIPE_EOF              = GEARMAN_PIPE_EOF;
    public const GEARMAN_QUEUE_ERROR           = GEARMAN_QUEUE_ERROR;
    public const GEARMAN_FLUSH_DATA            = GEARMAN_FLUSH_DATA;
    public const GEARMAN_SEND_BUFFER_TOO_SMALL = GEARMAN_SEND_BUFFER_TOO_SMALL;
    public const GEARMAN_IGNORE_PACKET         = GEARMAN_IGNORE_PACKET;
    public const GEARMAN_UNKNOWN_OPTION        = GEARMAN_UNKNOWN_OPTION;
    public const GEARMAN_TIMEOUT               = GEARMAN_TIMEOUT;
    public const GEARMAN_MAX_RETURN            = GEARMAN_MAX_RETURN;
}

if (!extension_loaded('gearman')) {
    class_alias(gearman\GearmanClient::class, \GearmanClient::class);
    class_alias(gearman\GearmanWorker::class, 'GearmanWorker');
    class_alias(gearman\GearmanJob::class, 'GearmanJob');
}
