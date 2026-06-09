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
                "require_once __DIR__.'/../lib/rtorrent/watchdog.php';",
                "rtorrentProcessStart(\$user, \$logCallback, \$state['startMarker'])",
            ]
        );
        $this->pmssAssertRepoFileContainsString('scripts/lib/rtorrent/watchdog.php', 'function pmssCheckRtorrentCleanupStaleSocket(');
        $this->pmssAssertRepoFileNotContainsStrings($path, ["@exec('/scripts/listUsers.php'", '/^[a-z][a-z0-9]{0,7}$/']);
    }
}
