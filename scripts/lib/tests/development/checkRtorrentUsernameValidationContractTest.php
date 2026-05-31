<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class checkRtorrentUsernameValidationContractTest extends TestCase
{
    public function testSharedListUsersParserRemainsPreferred(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/cron/checkRtorrent.php',
            ["pmssListManagedUsersResult('/scripts/listUsers.php')", 'function pmssCheckRtorrentCleanupStaleSocket(']
        );
    }

    public function testLegacyInlineUsernameParsingStaysRemoved(): void
    {
        $path = 'scripts/cron/checkRtorrent.php';
        $this->pmssAssertRepoFileNotContainsStrings($path, ["@exec('/scripts/listUsers.php'", '/^[a-z][a-z0-9]{0,7}$/']);
        $this->pmssAssertRepoFileContainsString($path, 'rtorrentProcessStart($user, $logCallback, $startMarkerState)');
    }
}
