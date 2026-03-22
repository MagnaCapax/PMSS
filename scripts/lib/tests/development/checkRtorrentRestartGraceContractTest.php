<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class checkRtorrentRestartGraceContractTest extends TestCase
{
    private function loadSource(): string
    {
        return $this->pmssReadRepoFile('scripts/cron/checkRtorrent.php');
    }

    public function testRestartGraceKeepsStableMarkerAndThresholds(): void
    {
        $src = $this->loadSource();

        $this->assertStringContainsString("'/tmp/.pmss-rtorrent-restart-'.\$user", $src);
        $this->assertStringContainsString('$restartAge < 7200', $src);
        $this->assertStringContainsString('$restartAge < 14400', $src);
        $this->assertStringContainsString('max(PMSS_RTORRENT_UNRESPONSIVE_GRACE, 600)', $src);
        $this->assertStringContainsString('max(PMSS_RTORRENT_UNRESPONSIVE_GRACE, 1200)', $src);
    }

    public function testRestartGraceLogicStaysInlineInWatchdog(): void
    {
        $src = $this->loadSource();

        $this->assertTrue(strpos($src, 'rtorrentProcessLastRestart'.'Age(') === false, 'Restart age helper should stay inlined in checkRtorrent');
        $this->assertTrue(strpos($src, 'rtorrentProcessExtended'.'Grace(') === false, 'Extended grace helper should stay inlined in checkRtorrent');
    }
}
