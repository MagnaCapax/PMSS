<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class checkRtorrentUsernameValidationContractTest extends TestCase
{
    public function testSharedListUsersParserRemainsPreferred(): void
    {
        $src = $this->pmssReadRepoFile('scripts/cron/checkRtorrent.php');

        $this->assertStringContainsString("pmssListManagedUsersResult('/scripts/listUsers.php')", $src);
    }

    public function testLegacyInlineUsernameParsingStaysRemoved(): void
    {
        $src = $this->pmssReadRepoFile('scripts/cron/checkRtorrent.php');

        $this->assertTrue(strpos($src, "@exec('/scripts/listUsers.php'") === false);
        $this->assertTrue(strpos($src, '/^[a-z][a-z0-9]{0,7}$/') === false);
    }
}
