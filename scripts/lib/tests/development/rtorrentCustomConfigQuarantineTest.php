<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/rtorrent/process.php';

class rtorrentCustomConfigQuarantineTest extends TestCase
{
    /** @var string */
    private $tempHome = '';

    private function setUpTempHome(): void
    {
        $base = sys_get_temp_dir().'/pmss-rtorrent-customrc-tests';
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        $this->tempHome = $base.'/home-'.bin2hex(random_bytes(4));
        @mkdir($this->tempHome, 0755, true);
    }

    private function tearDownTempHome(): void
    {
        if ($this->tempHome === '' || !is_dir($this->tempHome)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempHome, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $path) {
            if ($path->isDir()) {
                @rmdir($path->getPathname());
            } else {
                @unlink($path->getPathname());
            }
        }
        @rmdir($this->tempHome);
        $this->tempHome = '';
    }

    public function testQuarantineMovesRegularFile(): void
    {
        $this->setUpTempHome();
        try {
            $src = $this->tempHome.'/.rtorrent.rc.custom';
            @file_put_contents($src, "broken_line\n");

            $user = getenv('USER') ?: 'root';
            $logFn = function (string $message, bool $force = true): void {
                // no-op for test
            };

            $dst = \rtorrentCustomConfigQuarantine($this->tempHome, $user, $logFn);
            $this->assertTrue(is_string($dst) && $dst !== '');
            $this->assertTrue(!file_exists($src), 'Source file should be moved away');
            $this->assertTrue(is_file($dst), 'Destination file should exist');
        } finally {
            $this->tearDownTempHome();
        }
    }

    public function testQuarantineSkipsMissingFile(): void
    {
        $this->setUpTempHome();
        try {
            $user = getenv('USER') ?: 'root';
            $logFn = function (string $message, bool $force = true): void {
            };
            $dst = \rtorrentCustomConfigQuarantine($this->tempHome, $user, $logFn);
            $this->assertTrue($dst === null);
        } finally {
            $this->tearDownTempHome();
        }
    }

    public function testQuarantineRefusesSymlink(): void
    {
        $this->setUpTempHome();
        try {
            $target = $this->tempHome.'/target';
            @file_put_contents($target, 'x');
            $src = $this->tempHome.'/.rtorrent.rc.custom';
            if (@symlink($target, $src) === false) {
                throw new SkipTest('symlink() not available; skipping');
            }

            $user = getenv('USER') ?: 'root';
            $logFn = function (string $message, bool $force = true): void {
            };
            $dst = \rtorrentCustomConfigQuarantine($this->tempHome, $user, $logFn);
            $this->assertTrue($dst === null);
            $this->assertTrue(is_link($src), 'Symlink should remain untouched');
        } finally {
            $this->tearDownTempHome();
        }
    }

    public function testQuarantineRefusesUnexpectedOwnerWhenUserIsDifferent(): void
    {
        $this->setUpTempHome();
        try {
            $src = $this->tempHome.'/.rtorrent.rc.custom';
            @file_put_contents($src, "x\n");

            $logFn = function (string $message, bool $force = true): void {
            };
            $dst = \rtorrentCustomConfigQuarantine($this->tempHome, 'root', $logFn);
            $this->assertTrue($dst === null, 'Should refuse when file is not owned by root for user=root');
            $this->assertTrue(is_file($src), 'Source file should remain in place');
        } finally {
            $this->tearDownTempHome();
        }
    }
}

