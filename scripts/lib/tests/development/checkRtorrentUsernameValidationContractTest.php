<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class checkRtorrentUsernameValidationContractTest extends TestCase
{
    public function testSharedListUsersParserAndLaunchContractsRemain(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/cron/checkRtorrent.php' => [
                'required' => [
                    "pmssListManagedUsersResult('/scripts/listUsers.php')",
                    "require_once __DIR__.'/../lib/rtorrent/watchdog.php';",
                    "rtorrentProcessStart(\$user, \$logCallback, \$state['startMarker'])",
                ],
                'forbidden' => ["@exec('/scripts/listUsers.php'", '/^[a-z][a-z0-9]{0,7}$/'],
            ],
            'scripts/lib/rtorrent/watchdog.php' => [
                'required' => ['function pmssCheckRtorrentCleanupStaleSocket('],
            ],
        ]);
    }
}
