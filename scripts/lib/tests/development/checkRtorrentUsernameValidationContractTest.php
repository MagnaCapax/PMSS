<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class checkRtorrentUsernameValidationContractTest extends TestCase
{
    public function testSharedListUsersParserAndLaunchContractsRemain(): void
    {
        $path = 'scripts/cron/checkRtorrent.php';

        $this->pmssAssertRepoFileContainsAllStrings(
            $path,
            [
                "pmssListManagedUsersResult('/scripts/listUsers.php')",
                'function pmssCheckRtorrentCleanupStaleSocket(',
                "rtorrentProcessStart(\$user, \$logCallback, \$state['startMarker'])",
            ]
        );
        $this->pmssAssertRepoFileNotContainsStrings($path, ["@exec('/scripts/listUsers.php'", '/^[a-z][a-z0-9]{0,7}$/']);
    }
}
