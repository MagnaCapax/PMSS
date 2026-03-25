<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class checkRtorrentUsernameValidationContractTest extends TestCase
{
    private function loadSource(): string
    {
        return (string) file_get_contents(__DIR__.'/../../../cron/checkRtorrent.php');
    }

    public function testSharedListUsersParserRemainsPreferred(): void
    {
        $src = $this->loadSource();

        $this->assertStringContainsString("pmssListManagedUsersResult('/scripts/listUsers.php')", $src);
    }

    public function testLegacyInlineUsernameParsingStaysRemoved(): void
    {
        $src = $this->loadSource();

        $this->assertTrue(strpos($src, "@exec('/scripts/listUsers.php'") === false);
        $this->assertTrue(strpos($src, '/^[a-z][a-z0-9]{0,7}$/') === false);
    }
}
