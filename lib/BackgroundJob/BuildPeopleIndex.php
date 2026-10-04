<?php
declare(strict_types=1);

namespace OCA\ImmichSearch\BackgroundJob;

use OCA\ImmichSearch\Service\Config;
use OCA\ImmichSearch\Service\PeopleIndex;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/** Hourly: who may see which person (see PeopleIndex). New faces arrive with Immich's library scans. */
class BuildPeopleIndex extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private Config $config,
        private PeopleIndex $index,
    ) {
        parent::__construct($time);
        $this->setInterval(3600);
        $this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
        $this->setAllowParallelRuns(false);
    }

    protected function run($argument): void {
        if ($this->config->isConfigured()) {
            $this->index->rebuild();
        }
    }
}
