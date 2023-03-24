<?php
/**
 * @codeCoverageIgnore this is minimally emulation on windows for test
 */
namespace ryunosuke\hellowo\ext\gearman;

use Kicken\Gearman\Job\WorkerJob;

class GearmanJob
{
    private WorkerJob $job;

    public function __construct(WorkerJob $job)
    {
        $this->job = $job;
    }

    public function unique(): string
    {
        return substr(sha1($this->job->getUniqueId()), 0, 36);
    }

    public function workload(): string
    {
        return $this->job->getWorkload();
    }
}
