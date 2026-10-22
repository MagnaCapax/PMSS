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
}
