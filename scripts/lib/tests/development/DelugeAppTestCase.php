<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

putenv('PMSS_DELUGE_NO_ENTRYPOINT=1');
require_once dirname(__DIR__, 2).'/update/apps/deluge.php';

/**
 * Shared fixture bootstrap for tests covering the Deluge updater module.
 */
abstract class DelugeAppTestCase extends TestCase
{
    /** @var array<int,string> */
    protected $logs = [];

    /** @var callable */
    protected $logger;

    protected function pmssSetUpDelugeFixture(string $prefix): void
    {
        $this->pmssAssignTempDirArrayLogger('tempDir', $prefix, $this->logs, $this->logger, 0700);
    }
}
