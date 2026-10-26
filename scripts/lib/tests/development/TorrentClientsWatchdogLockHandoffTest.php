<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * The client watchdogs hold the per-client switch lock on fd 9 while they launch. A daemon that
 * inherits fd 9 keeps that lock for its whole lifetime, and every Configure switch then answers
 * "A ... switch is already running" until the daemon stops.
 */
final class TorrentClientsWatchdogLockHandoffTest extends TestCase
{
    public function testEveryWatchdogLaunchClosesTheSwitchLockDescriptor(): void
    {
        foreach ([
            'scripts/cron/transmissionInstancesCheck.php' => ['transmission --launch >/dev/null 2>&1 9>&-'],
            'scripts/cron/checkDelugeInstances.php' => ['--launch deluged >/dev/null 2>&1) 9>&-', '--launch deluge-web >/dev/null 2>&1) 9>&-'],
            'scripts/cron/checkQbittorrentInstances.php' => ['--launch --held --system 9>&-', 'qbittorrent-nox -d 9>&-'],
        ] as $file => $needles) {
            $source = $this->pmssReadRepoFile($file);
            $this->assertStringContainsString('exec 9>>', $source, $file);
            foreach ($needles as $needle) $this->assertStringContainsString($needle, $source, $file);
        }
    }

    public function testBackgroundDaemonStartedWithTheDescriptorClosedDoesNotHoldTheLock(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-watchdog-lock-');
        $lock = $dir.'/transmission.lock';
        $pidFile = $dir.'/daemon.pid';
        $daemon = 'sleep 5 >/dev/null 2>&1 </dev/null & echo $! >'.escapeshellarg($pidFile);
        foreach (['leaks' => $daemon, 'closed' => '{ '.$daemon.'; } 9>&-'] as $label => $launch) {
            exec('sh -c '.escapeshellarg('exec 9>>'.escapeshellarg($lock).'; flock -n 9 || exit 3; '.$launch), $unused, $rc);
            $this->assertSame(0, $rc, $label);
            exec('flock -n '.escapeshellarg($lock).' true', $unused, $free);
            $this->assertSame($label === 'closed' ? 0 : 1, $free, $label.': lock state after the watchdog shell exits');
            $pid = (int) trim((string) @file_get_contents($pidFile));
            if ($pid > 1) posix_kill($pid, SIGKILL);
            usleep(100000);
        }
    }
}
