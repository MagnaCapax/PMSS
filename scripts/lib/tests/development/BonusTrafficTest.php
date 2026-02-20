<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/bonusTraffic.php';

class BonusTrafficTest extends TestCase
{
    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/pmss-bonus-'.bin2hex(random_bytes(4));
        @mkdir($dir, 0755, true);
        return $dir;
    }

    public function testReadBonusTrafficMissingReturnsZero(): void
    {
        $dir = $this->makeTempDir();
        $this->assertEquals(0, \pmssBonusTrafficReadGiB($dir.'/missing'));
    }

    public function testReadBonusTrafficParsesValue(): void
    {
        $dir = $this->makeTempDir();
        $file = $dir.'/bonus';
        @file_put_contents($file, "100GiB\n");
        $this->assertEquals(100, \pmssBonusTrafficReadGiB($file));
    }

    public function testReadBonusTrafficRejectsInvalid(): void
    {
        $dir = $this->makeTempDir();
        $file = $dir.'/bonus';
        @file_put_contents($file, "nope\n");
        $this->assertEquals(0, \pmssBonusTrafficReadGiB($file));
    }

    public function testReadBonusTrafficRejectsZero(): void
    {
        $dir = $this->makeTempDir();
        $file = $dir.'/bonus';
        @file_put_contents($file, "0\n");
        $this->assertEquals(0, \pmssBonusTrafficReadGiB($file));
    }

    public function testReadBonusTrafficRejectsSymlink(): void
    {
        if (!function_exists('symlink')) {
            throw new SkipTest('symlink unavailable');
        }
        $dir = $this->makeTempDir();
        $target = $dir.'/target';
        @file_put_contents($target, "50\n");
        $link = $dir.'/link';
        if (!@symlink($target, $link)) {
            throw new SkipTest('symlink creation failed');
        }
        $this->assertEquals(0, \pmssBonusTrafficReadGiB($link));
    }

    public function testWriteBonusTrafficWritesValue(): void
    {
        $dir = $this->makeTempDir();
        $file = $dir.'/bonus';
        $this->assertTrue(\pmssBonusTrafficWriteGiB($file, 75));
        $this->assertEquals("75", trim((string) @file_get_contents($file)));
    }

    public function testWriteBonusTrafficRejectsNegative(): void
    {
        $dir = $this->makeTempDir();
        $file = $dir.'/bonus';
        $this->assertTrue(!\pmssBonusTrafficWriteGiB($file, -1));
        $this->assertTrue(!file_exists($file));
    }

    public function testWriteBonusTrafficRejectsSymlink(): void
    {
        if (!function_exists('symlink')) {
            throw new SkipTest('symlink unavailable');
        }
        $dir = $this->makeTempDir();
        $target = $dir.'/target';
        @file_put_contents($target, "1\n");
        $link = $dir.'/link';
        if (!@symlink($target, $link)) {
            throw new SkipTest('symlink creation failed');
        }
        $this->assertTrue(!\pmssBonusTrafficWriteGiB($link, 10));
    }

    public function testRemoveBonusTrafficMissingReturnsTrue(): void
    {
        $dir = $this->makeTempDir();
        $file = $dir.'/bonus';
        $this->assertTrue(\pmssBonusTrafficRemove($file));
    }

    public function testRemoveBonusTrafficRemovesFile(): void
    {
        $dir = $this->makeTempDir();
        $file = $dir.'/bonus';
        @file_put_contents($file, "10\n");
        $this->assertTrue(\pmssBonusTrafficRemove($file));
        $this->assertTrue(!file_exists($file));
    }

    public function testRemoveBonusTrafficRejectsSymlink(): void
    {
        if (!function_exists('symlink')) {
            throw new SkipTest('symlink unavailable');
        }
        $dir = $this->makeTempDir();
        $target = $dir.'/target';
        @file_put_contents($target, "1\n");
        $link = $dir.'/link';
        if (!@symlink($target, $link)) {
            throw new SkipTest('symlink creation failed');
        }
        $this->assertTrue(!\pmssBonusTrafficRemove($link));
    }
}
