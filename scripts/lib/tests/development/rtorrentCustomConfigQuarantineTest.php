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

    public function testQuarantineMovesRegularFile(): void
    {
        $src = $this->tempHome.'/.rtorrent.rc.custom';
        @file_put_contents($src, "broken_line\n");

        $user = $this->resolveTestUser();
        $logFn = $this->noOpLogFn();

        $dst = \rtorrentCustomConfigQuarantine($this->tempHome, $user, $logFn);
        $this->assertTrue(is_string($dst) && $dst !== '');
        $this->assertTrue(!file_exists($src), 'Source file should be moved away');
        $this->assertTrue(is_file($dst), 'Destination file should exist');
    }

    public function testQuarantineSkipsMissingFile(): void
    {
        $user = $this->resolveTestUser();
        $logFn = $this->noOpLogFn();
        $dst = \rtorrentCustomConfigQuarantine($this->tempHome, $user, $logFn);
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

        $user = $this->resolveTestUser();
        $logFn = $this->noOpLogFn();
        $dst = \rtorrentCustomConfigQuarantine($this->tempHome, $user, $logFn);
        $this->assertTrue($dst === null);
        $this->assertTrue(is_link($src), 'Symlink should remain untouched');
    }

    public function testQuarantineRefusesUnexpectedOwnerWhenUserIsDifferent(): void
    {
        $src = $this->tempHome.'/.rtorrent.rc.custom';
        @file_put_contents($src, "x\n");

        $logFn = $this->noOpLogFn();
        $dst = \rtorrentCustomConfigQuarantine($this->tempHome, 'root', $logFn);
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

    public function testLegacyDirectiveDetectorFindsRemovedAliases(): void
    {
        $content = "schedule = watch,1,1,\"load_start=~/watch/*.torrent\"\n"
            ."schedule_remove = watch\n"
            ."execute = sh,-c,echo ok\n";

        $this->assertEquals(
            ['schedule', 'schedule_remove', 'execute'],
            \rtorrentCustomConfigFindLegacyDirectives($content)
        );
    }

    public function testLegacyDirectiveDetectorFindsNormalizedTemplateOptions(): void
    {
        $content = "tracker_numwant = -1\n"
            ."use_udp_trackers = yes\n"
            ."port_range = 50000-60000\n"
            ."check_hash = no\n"
            ."load_start = ~/watch/test.torrent\n"
            ."load_start_verbose = ~/watch/test-verbose.torrent\n";

        $this->assertEquals(
            ['tracker_numwant', 'use_udp_trackers', 'port_range', 'check_hash', 'load_start', 'load_start_verbose'],
            \rtorrentCustomConfigFindLegacyDirectives($content)
        );
    }

    public function testLegacyDirectiveDetectorFindsObsoleteRemovedOptions(): void
    {
        $content = "umask = 0002\n"
            ."hash_interval = 300\n"
            ."hash_max_tries = 2\n";

        $this->assertEquals(
            ['umask', 'hash_interval', 'hash_max_tries'],
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
        $user = $this->resolveTestUser();
        $logFn = function (string $message, bool $force = true) use (&$messages): void {
            $messages[] = $message;
        };

        $dst = \rtorrentCustomConfigQuarantine($this->tempHome, $user, $logFn);
        $this->assertTrue(is_string($dst) && $dst !== '');
        $this->assertTrue(
            in_array('Custom rTorrent config still uses legacy PMSS-migrated directives: schedule, execute', $messages, true),
            'Quarantine should log detected legacy directives before moving the file'
        );
    }
}
