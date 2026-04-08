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

    public function testQuarantineMovesRegularFile(): void
    {
        $src = $this->tempHome.'/.rtorrent.rc.custom';
        @file_put_contents($src, "broken_line\n");

        $user = $this->resolveTestUser();
        $logFn = function (string $message, bool $force = true): void {
            // no-op for test
        };

        $dst = \rtorrentCustomConfigQuarantine($this->tempHome, $user, $logFn);
        $this->assertTrue(is_string($dst) && $dst !== '');
        $this->assertTrue(!file_exists($src), 'Source file should be moved away');
        $this->assertTrue(is_file($dst), 'Destination file should exist');
    }

    public function testQuarantineSkipsMissingFile(): void
    {
        $user = $this->resolveTestUser();
        $logFn = function (string $message, bool $force = true): void {
        };
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
        $logFn = function (string $message, bool $force = true): void {
        };
        $dst = \rtorrentCustomConfigQuarantine($this->tempHome, $user, $logFn);
        $this->assertTrue($dst === null);
        $this->assertTrue(is_link($src), 'Symlink should remain untouched');
    }

    public function testQuarantineRefusesUnexpectedOwnerWhenUserIsDifferent(): void
    {
        $src = $this->tempHome.'/.rtorrent.rc.custom';
        @file_put_contents($src, "x\n");

        $logFn = function (string $message, bool $force = true): void {
        };
        $dst = \rtorrentCustomConfigQuarantine($this->tempHome, 'root', $logFn);
        $this->assertTrue($dst === null, 'Should refuse when file is not owned by root for user=root');
        $this->assertTrue(is_file($src), 'Source file should remain in place');
    }
}
