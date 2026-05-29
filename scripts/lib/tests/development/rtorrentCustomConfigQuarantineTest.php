<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/rtorrent/process.php';

class rtorrentCustomConfigQuarantineTest extends TestCase
{
    /** @var string */
    private $tempHome = '';

    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempHome', 'home', 0755, sys_get_temp_dir().'/pmss-rtorrent-customrc-tests');
    }

    private function resolveTestUser(): string
    {
        $user = getenv('USER');
        if ($user === false || $user === '') {
            $user = get_current_user();
        }
        return $user !== '' ? $user : 'root';
    }

    private function noOpLogFn(): callable
    {
        return static function (string $message, bool $force = true): void {
        };
    }

    private function quarantineCustomConfig(string $user = '', ?callable $logFn = null): ?string
    {
        return \rtorrentCustomConfigQuarantine(
            $this->tempHome,
            $user !== '' ? $user : $this->resolveTestUser(),
            $logFn ?? $this->noOpLogFn()
        );
    }

    public function testQuarantineMovesRegularFile(): void
    {
        $src = $this->tempHome.'/.rtorrent.rc.custom';
        @file_put_contents($src, "broken_line\n");

        $dst = $this->quarantineCustomConfig();
        $this->assertTrue(is_string($dst) && $dst !== '');
        $this->assertTrue(!file_exists($src), 'Source file should be moved away');
        $this->assertTrue(is_file($dst), 'Destination file should exist');
    }

    public function testQuarantineSkipsMissingFile(): void
    {
        $dst = $this->quarantineCustomConfig();
        $this->assertTrue($dst === null);
    }

    public function testQuarantineRefusesSymlink(): void
    {
        $target = $this->tempHome.'/target';
        @file_put_contents($target, 'x');
        $src = $this->tempHome.'/.rtorrent.rc.custom';
        if (@symlink($target, $src) === false) {
            throw new SkipTest('symlink() not available; skipping');
        }

        $dst = $this->quarantineCustomConfig();
        $this->assertTrue($dst === null);
        $this->assertTrue(is_link($src), 'Symlink should remain untouched');
    }

    public function testQuarantineRefusesUnexpectedOwnerWhenUserIsDifferent(): void
    {
        $src = $this->tempHome.'/.rtorrent.rc.custom';
        @file_put_contents($src, "x\n");

        $dst = $this->quarantineCustomConfig('root');
        $this->assertTrue($dst === null, 'Should refuse when file is not owned by root for user=root');
        $this->assertTrue(is_file($src), 'Source file should remain in place');
    }

    public function testLegacyDirectiveDetectorReturnsEmptyArrayForModernConfig(): void
    {
        $content = "schedule2 = watch,1,1,\"load.start=~/watch/*.torrent\"\n"
            ."execute.nothrow = chmod,770,~/.rtorrent.socket\n"
            ."trackers.use_udp.set = yes\n";

        $this->assertEquals([], \rtorrentCustomConfigFindLegacyDirectives($content));
    }

    public function testLegacyDirectiveDetectorPreservesDirectiveOrderSnapshot(): void
    {
        $content = '';
        foreach (\pmssRtorrentLegacyDirectiveNames() as $label) {
            $content .= $label." = value\n";
        }

        $this->assertEquals(
            \pmssRtorrentLegacyDirectiveNames(),
            \rtorrentCustomConfigFindLegacyDirectives($content)
        );
    }

    public function testLegacyDirectiveDetectorIgnoresCommentedLegacyLines(): void
    {
        $content = "# schedule = watch,1,1,\"load_start=~/watch/*.torrent\"\n"
            ."# execute = sh,-c,echo ok\n"
            ."; use_udp_trackers = yes\n";

        $this->assertEquals([], \rtorrentCustomConfigFindLegacyDirectives($content));
    }

    public function testQuarantineLogsLegacyDirectivesBeforeMovingFile(): void
    {
        $src = $this->tempHome.'/.rtorrent.rc.custom';
        @file_put_contents($src, "schedule = watch,1,1,\"load_start=~/watch/*.torrent\"\nexecute = sh,-c,echo ok\n");

        $messages = [];
        $logFn = function (string $message, bool $force = true) use (&$messages): void {
            $messages[] = $message;
        };

        $dst = $this->quarantineCustomConfig('', $logFn);
        $this->assertTrue(is_string($dst) && $dst !== '');
        $this->assertTrue(
            in_array('Custom rTorrent config still uses legacy PMSS-migrated directives: schedule, execute', $messages, true),
            'Quarantine should log detected legacy directives before moving the file'
        );
    }
}
