<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/torrentClientsResolve.php';

/** Exercise restart policy without launching an executor or sleeping. */
final class TorrentClientsExecutorFallbackTest extends TestCase
{
    private const SYSTEM = '/usr/local/bin/rtorrent';
    private const STORE = "'/home/.pmss/clients/rtorrent/0.16.25/rtorrent' -D";

    public function testThirdConsecutiveFastExitSelectsSystemOnNextLaunch(): void
    {
        $lastFailed = '';
        $fastExits = 0;
        foreach ([0.0, 1.5, 19.999] as $runtime) {
            $this->assertSame(self::STORE, \pmssRtorrentExecutorFallbackCommand(self::STORE, $lastFailed, $fastExits));
            $fastExits = \pmssRtorrentExecutorFastExits(self::STORE, $lastFailed, $fastExits, $runtime);
            $lastFailed = self::STORE;
        }
        $this->assertSame(3, $fastExits);
        $this->assertSame(self::SYSTEM, \pmssRtorrentExecutorFallbackCommand(self::STORE, $lastFailed, $fastExits));
        // Re-resolving the failed selection keeps the fallback for subsequent restarts.
        $this->assertSame(self::SYSTEM, \pmssRtorrentExecutorFallbackCommand(self::STORE, $lastFailed, $fastExits));
    }

    public function testTwentySecondOrLongerStoreRunClearsFastExitCount(): void
    {
        foreach ([20.0, 20.001, 300.0] as $runtime) {
            $count = \pmssRtorrentExecutorFastExits(self::STORE, self::STORE, 2, $runtime);
            $this->assertSame(0, $count);
            $this->assertSame(self::STORE, \pmssRtorrentExecutorFallbackCommand(self::STORE, self::STORE, $count));
            $this->assertSame(1, \pmssRtorrentExecutorFastExits(self::STORE, self::STORE, $count, 1.0));
        }
    }

    public function testDifferentCommandGetsFreshAttemptsIncludingChangedArguments(): void
    {
        foreach (["'/home/.pmss/clients/rtorrent/0.9.8-r16/rtorrent'", self::STORE.' --new-argument'] as $command) {
            $this->assertSame($command, \pmssRtorrentExecutorFallbackCommand($command, self::STORE, 3));
            $count = \pmssRtorrentExecutorFastExits($command, self::STORE, 3, 1.0);
            $this->assertSame(1, $count);
            $this->assertSame($command, \pmssRtorrentExecutorFallbackCommand($command, $command, $count));
        }
    }

    public function testSystemCommandAlwaysStaysSystemAndIsNotCounted(): void
    {
        foreach ([0, 1, 2, 3, 10] as $count) {
            $this->assertSame(self::SYSTEM, \pmssRtorrentExecutorFallbackCommand(self::SYSTEM, self::STORE, $count));
            $this->assertSame(self::SYSTEM, \pmssRtorrentExecutorFallbackCommand(self::SYSTEM, self::SYSTEM, $count));
            $this->assertSame(0, \pmssRtorrentExecutorFastExits(self::SYSTEM, self::STORE, $count, 0.0));
        }
    }

    public function testFallbackRecordNamesOnlyTheBuildThatFailedAndClears(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rt-fallback-');
        $this->assertSame('', \pmssRtorrentExecutorFallbackVersion($home), 'no record before any fallback');
        \pmssRtorrentExecutorFallbackMark($home, self::SYSTEM);
        $this->assertSame('', \pmssRtorrentExecutorFallbackVersion($home), 'the system build is never recorded');
        \pmssRtorrentExecutorFallbackMark($home, self::STORE);
        $this->assertSame('0.16.25', \pmssRtorrentExecutorFallbackVersion($home));
        \pmssRtorrentExecutorFallbackMark($home, null);
        $this->assertSame('', \pmssRtorrentExecutorFallbackVersion($home));
        // A record path the account replaced with a link is neither written through nor read.
        symlink('/etc/hostname', $home.'/.config/pmss/torrentClients/rtorrent.fallback');
        \pmssRtorrentExecutorFallbackMark($home, self::STORE);
        $this->assertSame('', \pmssRtorrentExecutorFallbackVersion($home));
        $this->assertTrue(is_link($home.'/.config/pmss/torrentClients/rtorrent.fallback'));
    }

    public function testPanelSaysPendingRestartUnlessTheExecutorFellBack(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClientsOther.php');
        $this->assertStringContainsString("pmssRtorrentExecutorFallbackVersion(\$home) === basename(\$target)", $source);
        $this->assertStringContainsString("' starts at the next rTorrent restart'", $source);
        $executor = (string) file_get_contents(dirname(__DIR__, 4).'/etc/skel/.rtorrentExecute.php');
        $this->assertStringContainsString('pmssRtorrentExecutorFallbackMark(__DIR__', $executor);
    }
}
