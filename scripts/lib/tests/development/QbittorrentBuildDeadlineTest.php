<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentBuild.php';
require_once __DIR__.'/../../user/credentialProcess.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/qbittorrentBuild.php';

/** Execute only suite-owned fake binaries on a testing machine, never the control node. */
final class QbittorrentBuildDeadlineTest extends TestCase
{
    /** Advance deadline time after the child starts; only yield briefly for process scheduling. */
    private function deadlineOps(string $ready): array
    {
        $now = 0.0;
        $started = microtime(true);
        return ['clock' => static function () use (&$now, $started): float {
            // Keep a real emergency bound if the fixture could not start at all.
            return max($now, microtime(true) - $started);
        }, 'pause' => static function () use (&$now, $ready): void {
            usleep(1000);
            if (is_file($ready)) $now += 0.25;
        }];
    }

    public function testVersionProbeCannotWaitForADescendantHoldingStdoutOpen(): void
    {
        $root = $this->pmssMakeTempDir('pmss-qbt-probe-deadline-');
        $ready = $root.'/ready';
        $binary = $this->pmssWriteFile($root.'/qbittorrent-nox', "#!/bin/sh\ntouch ".escapeshellarg($ready)."\nsleep 30 &\nexit 0\n");
        chmod($binary, 0700);
        foreach (['pmssQbittorrentBuildProbe', 'pmssQbittorrentFrontendBuildProbe'] as $probe) {
            if (is_file($ready)) unlink($ready);
            $start = microtime(true);
            $this->assertSame(null, $probe($binary, $this->deadlineOps($ready)));
            $this->assertTrue(is_file($ready), 'The descendant fixture must actually start');
            $this->assertTrue(microtime(true) - $start < 1, 'Version probe ignored the injected clock or failed to reap its process group');
        }
    }

    public function testSuccessfulProbeCapturesExitStatusAndRejectsExcessOutput(): void
    {
        $root = $this->pmssMakeTempDir('pmss-qbt-probe-output-');
        foreach (["printf 'qBittorrent v5.3.0\\n'" => "qBittorrent v5.3.0\n", 'exit 1' => null,
            'head -c 8192 /dev/zero' => null] as $body => $expected) {
            $binary = $this->pmssWriteFile($root.'/qbittorrent-nox', "#!/bin/sh\n".$body."\n");
            chmod($binary, 0700);
            foreach (['pmssQbittorrentBuildProbe', 'pmssQbittorrentFrontendBuildProbe'] as $probe)
                $this->assertSame($expected, $probe($binary));
        }
    }

    public function testFifoAndLinkedVersionCachesAreRefusedWithoutBlockingOnBothSides(): void
    {
        if (!function_exists('posix_mkfifo')) throw new SkipTest('FIFO fixtures need POSIX');
        $home = $this->pmssMakeTempDir('pmss-qbt-cache-fifo-');
        $binary = $this->pmssWriteFile($home.'/qbittorrent-nox', 'fixture');
        $cache = $home.'/.config/pmss/torrentClients/qbittorrent.version-'.hash('sha256', realpath($binary).':'.filemtime($binary));
        $this->pmssEnsureDir(dirname($cache));
        posix_mkfifo($cache, 0600);
        foreach (['pmssQbittorrentBuildVersion', 'pmssQbittorrentFrontendBuildVersion'] as $version) {
            $start = microtime(true);
            $this->assertSame(null, $version($home, $binary, ['probe' => static function () { return null; }]));
            $this->assertTrue(microtime(true) - $start < 0.5);
        }
        unlink($cache);
        $target = $this->pmssWriteFile($home.'/target', '5.3.0');
        $this->pmssCreateSymlinkOrSkip($target, $cache);
        foreach (['pmssQbittorrentBuildVersion', 'pmssQbittorrentFrontendBuildVersion'] as $version)
            $this->assertSame(null, $version($home, $binary, ['probe' => static function () { return null; }]));
    }

    public function testBothBoundedReadersRejectSpecialOversizedAndWrongOwnerFiles(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-cache-reader-');
        $path = $this->pmssWriteFile($home.'/cache', "5.3.0\n");
        foreach (['pmssReadRegularFileContentsVerified', 'PMSS\\Customer\\pmssReadRegularFileContentsVerified'] as $read) {
            $this->assertSame("5.3.0\n", $read($path, null, 128));
            $this->assertSame(null, $read($path, null, 2));
            $this->assertSame(null, $read($path, posix_geteuid() + 1, 128));
            $this->assertSame(null, $read($home, null, 128));
        }
    }

    public function testCredentialSubprocessDeadlineAndCredentialDelivery(): void
    {
        $root = $this->pmssMakeTempDir('pmss-qbt-account-deadline-');
        $ready = $root.'/ready';
        $fake = $this->pmssWriteFile($root.'/stalled', "#!/bin/sh\ntouch ".escapeshellarg($ready)."\nsleep 30 &\nexit 0\n");
        chmod($fake, 0700);
        $output = [];
        $start = microtime(true);
        $this->assertSame(124, \pmssCredentialProcessRun(escapeshellarg($fake), null, $output, false, 45, $this->deadlineOps($ready)));
        $this->assertTrue(is_file($ready));
        $this->assertTrue(microtime(true) - $start < 10, 'the injected deadline returns well before the 30 s child or the 45 s budget');
        $this->assertSame(0, \pmssCredentialProcessRun('cat', 'separate-service-credential', $output, false, 2));
        $this->assertSame(['separate-service-credential'], $output);
    }
}
