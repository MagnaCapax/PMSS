<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../lighttpd/symlinkRepair.php';

class LighttpdSymlinkRepairTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-lighttpd-repair-']; }

    public function testRepairsOnlyNamedSymlinksWithoutFollowingTargets(): void
    {
        $username = posix_getpwuid(posix_geteuid())['name'];
        $home = $this->tempDir.'/account';
        $parent = $home.'/.lighttpd';
        $this->pmssEnsureDir($parent);
        $target = $this->tempDir.'/outside';
        $this->pmssWriteFile($target, 'keep');

        foreach (['upload' => true, 'access.log' => false, 'error.log' => false] as $name => $directory) {
            $path = $parent.'/'.$name;
            $this->pmssCreateSymlinkOrSkip($target, $path);
            $this->assertTrue(\pmssLighttpdSymlinkRepairOne($username, $home, $name, $directory));
            $this->assertFalse(is_link($path));
            $this->assertSame($directory, is_dir($path));
            if (!$directory) $this->assertSame(0640, fileperms($path) & 0777);
            $this->assertSame('keep', file_get_contents($target));
            $this->assertTrue(\pmssLighttpdSymlinkRepairOne($username, $home, $name, $directory));
        }
    }

    public function testRefusesLinkedParentAndUnexpectedNames(): void
    {
        $username = posix_getpwuid(posix_geteuid())['name'];
        $home = $this->tempDir.'/account';
        $outside = $this->tempDir.'/outside';
        $this->pmssEnsureDir($home);
        $this->pmssEnsureDir($outside);
        $this->pmssCreateSymlinkOrSkip($outside, $home.'/.lighttpd');

        $this->assertFalse(\pmssLighttpdSymlinkRepairOne($username, $home, 'upload', true));
        $this->assertFalse(\pmssLighttpdSymlinkRepairOne($username, $home, '../other', false));
        $this->assertFalse(\pmssLighttpdSymlinkRepairOne($username, $home, 'access.log', true));
        $this->assertTrue(is_link($home.'/.lighttpd'));
    }

    public function testQuotaRequiresSuccessfulLiveNonExhaustedRow(): void
    {
        $bin = $this->tempDir.'/bin';
        $this->pmssEnsureDir($bin);
        $output = $this->tempDir.'/quota-output';
        $this->pmssWriteExecutableFiles($bin, [
            'quota' => '#!/bin/sh' . "\n" . 'cat '.escapeshellarg($output)."\n",
        ]);
        $this->pmssWithEnv(['PATH' => $bin.':'.getenv('PATH')], function () use ($output): void {
            file_put_contents($output, "/dev/sda1 1G 9G 10G 0 0 0\n");
            $this->assertTrue(\pmssLighttpdSymlinkRepairQuotaAllows('account'));
            file_put_contents($output, "/dev/sda1 10G* 9G 10G 0 0 0\n");
            $this->assertFalse(\pmssLighttpdSymlinkRepairQuotaAllows('account'));
            file_put_contents($output, "/dev/sda1 10G 9G 10G 0 0 0\n");
            $this->assertFalse(\pmssLighttpdSymlinkRepairQuotaAllows('account'));
            file_put_contents($output, "quota unavailable\n");
            $this->assertFalse(\pmssLighttpdSymlinkRepairQuotaAllows('account'));
        });
    }
}
