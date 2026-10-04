<?php
declare(strict_types=1);

namespace OCA\ImmichSearch\BackgroundJob;

use OCA\ImmichSearch\Service\PeopleIndex;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/** One-off rebuild requested from the admin settings. */
class RebuildPeopleIndexNow extends QueuedJob {
    public function __construct(
        ITimeFactory $time,
        private PeopleIndex $index,
    ) {
        parent::__construct($time);
    }

    protected function run($argument): void {
        $this->index->rebuild();
    }
}
