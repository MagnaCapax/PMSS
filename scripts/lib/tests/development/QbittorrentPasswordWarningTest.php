<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentPasswordStopped.php';

/** The optional qBittorrent warning leaves the system-password result in control. */
final class QbittorrentPasswordWarningTest extends TestCase
{
    public function testOptionalSyncFailureWarnsOnStderrWithoutChangingTheCallerStatus(): void
    {
        foreach ([0, 1] as $status) {
            $script = $this->pmssMakeTempPath('pmss-password-warning-', '.php');
            $this->pmssWriteFile($script, '<?php require_once '.var_export(__DIR__.'/../../user/qbittorrentPasswordStopped.php', true)
                .'; pmssQbittorrentPasswordSyncWarning(false, true); exit('.$status.');');
            $pipes = [];
            $child = proc_open(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertTrue(is_resource($child));
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
            $this->assertSame($status, proc_close($child));
            $this->assertSame('', $stdout);
            $this->assertStringContainsString('qBittorrent password synchronization failed', $stderr);
        }
        $warnings = [];
        foreach ([[true, true], [false, false], [true, false]] as list($updated, $installed))
            \pmssQbittorrentPasswordSyncWarning($updated, $installed, static function ($message) use (&$warnings): void { $warnings[] = $message; });
        $this->assertSame([], $warnings);
        // changePw is a top-level CLI without an ops seam; check only the warning helper wiring.
        $this->pmssAssertRepoFileContainsString('scripts/changePw.php', 'pmssQbittorrentPasswordSyncWarning($qbittorrentUpdated,');
    }
}
