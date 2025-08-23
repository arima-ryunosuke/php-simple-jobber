<?php
/**
 * @codeCoverageIgnore this is minimally emulation on windows for test
 */
namespace ryunosuke\hellowo\ext\gearman;

use Kicken\Gearman\Job\WorkerJob;

if (class_exists(\GearmanJob::class)) {
    class GearmanJob extends \GearmanJob { }
}
else {
    class GearmanJob
    {
        private WorkerJob $job;

        public function __construct(WorkerJob $job)
        {
            $this->job = $job;
        }

        public function unique(): string
        {
            return $this->job->getUniqueId();
        }

        public function handle(): string
        {
            return $this->job->getJobHandle();
        }

        public function workload(): string
        {
            return $this->job->getWorkload();
        }
    }
}
