<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentResume.php';

/** Sparse resume fixtures exercise metadata scans without allocating torrent data. */
final class QbittorrentResumeTest extends TestCase
{
    private $home;
    private $state;
    private $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = $this->pmssMakeTrackedHomeRoot('pmss-qbt-resume-home-').'/fixtur01';
        mkdir($this->home);
        $this->state = $this->pmssMakeTempDir('pmss-qbt-resume-state-');
        $this->events = [];
    }

    private function resume(int $size, string $relative = '.local/share/qBittorrent', string $name = 'fixture.fastresume'): string
    {
        $path = $this->pmssWriteFile($this->home.'/'.$relative.'/BT_backup/'.$name, '');
        $handle = fopen($path, 'r+');
        try {
            $this->assertTrue(ftruncate($handle, $size));
        } finally {
            fclose($handle);
        }
        return $path;
    }

    private function observe(string $user = 'fixtur01'): bool
    {
        return \pmssQbittorrentResumeObserve($user, $this->home, [
            'stateDir' => $this->state,
            'log' => function ($user, $message, $event): void { $this->events[] = [$user, $message, $event]; },
        ]);
    }

    public function testOnlyStrictlyOversizedFilesAreReported(): void
    {
        foreach ([0, 536870910, 536870911, 536870912, 1900000000] as $size) {
            $path = $this->resume($size);
            $sizes = array_values(\pmssQbittorrentResumeOversized($this->home));
            $this->assertSame($size > 536870911 ? [$size] : [], $sizes);
            clearstatcache(true, $path);
            $this->assertSame($size, filesize($path));
        }
    }

    public function testStandardAndLegacyPathsHaveIndependentFindings(): void
    {
        foreach (['.local/share/qBittorrent', '.local/share/data/qBittorrent', '.config/qBittorrent'] as $relative) {
            $this->resume(536870912, $relative);
        }
        $findings = \pmssQbittorrentResumeOversized($this->home);
        $this->assertSame(3, count($findings));
        foreach (array_keys($findings) as $key) $this->assertSame(64, strlen($key));
    }

    public function testMissingBackupDirectoriesAreQuietAndNonDirectoryAncestorsAreInconclusive(): void
    {
        $this->assertSame([], \pmssQbittorrentResumeOversized($this->home));
        $this->assertTrue($this->observe());
        $this->assertSame([], $this->events);
        $this->pmssWriteFile($this->home.'/.local', 'not a directory');
        $this->assertSame(null, \pmssQbittorrentResumeOversized($this->home));
    }

    public function testOtherExtensionsDirectoriesAndLinkedFilesAreIgnored(): void
    {
        $target = $this->resume(536870912, '.local/share/qBittorrent', 'fixture.torrent');
        $directory = dirname($target);
        mkdir($directory.'/directory.fastresume');
        $this->pmssCreateSymlinkOrSkip($target, $directory.'/linked.fastresume');
        $this->pmssCreateSymlinkOrSkip($directory.'/missing', $directory.'/dangling.fastresume');
        $this->assertSame([], \pmssQbittorrentResumeOversized($this->home));
    }

    public function testMissingAndUnsafeHomesAreInconclusive(): void
    {
        foreach ([$this->home.'/missing', $this->home.'/../fixtur01', "bad\0path"] as $home) {
            $this->assertSame(null, \pmssQbittorrentResumeOversized($home));
        }
        $linked = dirname($this->home).'/fixtur02';
        $this->pmssCreateSymlinkOrSkip($this->home, $linked);
        $this->assertSame(null, \pmssQbittorrentResumeOversized($linked));
    }

    public function testNewFindingsLogOnceWithoutNamesAndUsePrivateState(): void
    {
        $path = $this->resume(1900000000, '.local/share/qBittorrent', 'private-torrent.fastresume');
        $this->assertTrue($this->observe());
        $this->assertTrue($this->observe());
        $this->assertSame(1, count($this->events));
        $this->assertStringContainsString('size=1900000000 limit=536870911', $this->events[0][1]);
        $this->assertSame('qbittorrent_resume_oversized', $this->events[0][2]['event']);
        $state = file_get_contents($this->state.'/fixtur01.json');
        $this->assertStringNotContainsString(basename($path), $state.json_encode($this->events));
        $this->assertSame(0600, fileperms($this->state.'/fixtur01.json') & 0777);
        $this->assertSame(0700, fileperms($this->state) & 0777);
        $this->resume(1900000001, '.local/share/qBittorrent', basename($path));
        $this->assertTrue($this->observe());
        $this->assertSame(1, count($this->events));
        $this->resume(536870912, '.local/share/qBittorrent', 'second.fastresume');
        $this->assertTrue($this->observe());
        $this->assertSame(2, count($this->events));
    }

    public function testRecoveryAndRemovalAllowAFreshWarningOnRecurrence(): void
    {
        foreach ([true, false] as $shrink) {
            $path = $this->resume(536870912);
            $this->assertTrue($this->observe());
            if ($shrink) $this->resume(536870911);
            else unlink($path);
            $this->assertTrue($this->observe());
            $this->assertSame([], json_decode(file_get_contents($this->state.'/fixtur01.json'), true));
        }
        $this->assertSame(2, count($this->events));
    }

    public function testLinkedBackupDirectoryRetainsPriorState(): void
    {
        $path = $this->resume(536870912);
        $this->assertTrue($this->observe());
        $previous = file_get_contents($this->state.'/fixtur01.json');
        $directory = dirname($path);
        rename($directory, $directory.'-saved');
        $this->pmssCreateSymlinkOrSkip($directory.'-saved', $directory);
        $this->assertFalse($this->observe());
        $this->assertSame($previous, file_get_contents($this->state.'/fixtur01.json'));
        $this->assertSame(1, count($this->events));
    }

    public function testInvalidAccountOrLinkedStateCannotPublish(): void
    {
        $this->resume(536870912);
        foreach (['../fixtur01', 'fixtur02', ''] as $user) $this->assertFalse($this->observe($user));
        $target = $this->pmssWriteFile($this->state.'/unmanaged.json', 'unchanged');
        $this->pmssCreateSymlinkOrSkip($target, $this->state.'/fixtur01.json');
        $this->assertFalse($this->observe());
        $this->assertSame('unchanged', file_get_contents($target));
        $this->assertSame([], $this->events);
    }

    public function testCronObservesBeforeRestartDecisions(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/cron/checkQbittorrentInstances.php', [
            "require_once __DIR__.'/../lib/user/qbittorrentResume.php';",
            'pmssQbittorrentResumeObserve($thisUser, $home);',
            'pmssQbittorrentWatchdogRunning($thisUser, $home)',
        ]);
    }
}
