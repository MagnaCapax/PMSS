<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/filesystem.php';

class HomeInodeDensityTest extends TestCase
{
    public function testParsesStatLineAndComputesBytesPerInode(): void
    {
        $stats = \pmssFilesystemStatLineParse('4096 1024 128');

        $this->assertEquals(['block_size' => 4096, 'blocks' => 1024, 'inodes' => 128], $stats);
        $this->assertEquals(32768.0, \pmssFilesystemBytesPerInode($stats));
    }

    public function testRejectsInvalidStatLines(): void
    {
        foreach (['', '4096 1024', '4096 1024 0', '4096 blocks 128', '-1 1024 128'] as $line) {
            $this->assertSame(null, \pmssFilesystemStatLineParse($line), 'line should be rejected: '.$line);
        }
    }

    public function testLogsOkWhenInodeDensityIsBelowThreshold(): void
    {
        $home = $this->pmssMakeTempDir('pmss-inode-ok-home-');
        $binDir = $this->pmssMakeLineOutputStub('stat', ['4096 1024 128'], 'pmss-inode-ok-bin-');
        $messages = [];

        $this->pmssWithPathPrefix($binDir, function () use (&$messages, $home): void {
            \pmssHomeInodeDensityCheck($this->pmssMakeArrayLogger($messages), $home, 262144);
        });

        $this->assertTrue($this->pmssMessagesContain($messages, '[OK] Home inode density'));
    }

    public function testLogsWarnWhenInodeDensityExceedsThreshold(): void
    {
        $home = $this->pmssMakeTempDir('pmss-inode-warn-home-');
        $binDir = $this->pmssMakeLineOutputStub('stat', ['4096 1048576 1024'], 'pmss-inode-warn-bin-');
        $messages = [];

        $this->pmssWithPathPrefix($binDir, function () use (&$messages, $home): void {
            \pmssHomeInodeDensityCheck($this->pmssMakeArrayLogger($messages), $home, 262144);
        });

        $this->assertTrue($this->pmssMessagesContain($messages, '[WARN] Home inode density'));
        $this->assertTrue($this->pmssMessagesContain($messages, 'media-stack workloads may exhaust inodes'));
    }

    public function testWarnsWhenStatFails(): void
    {
        $home = $this->pmssMakeTempDir('pmss-inode-fail-home-');
        $binDir = $this->pmssMakeExecutableStub('stat', "#!/bin/sh\nexit 9\n", 'pmss-inode-fail-bin-');
        $messages = [];

        $this->pmssWithPathPrefix($binDir, function () use (&$messages, $home): void {
            \pmssHomeInodeDensityCheck($this->pmssMakeArrayLogger($messages), $home, 262144);
        });

        $this->assertTrue($this->pmssMessagesContain($messages, 'stat rc=9'));
    }

    public function testSkipsWhenHomePathIsMissing(): void
    {
        $messages = [];

        \pmssHomeInodeDensityCheck($this->pmssMakeArrayLogger($messages), '/tmp/pmss-missing-home-path', 262144);

        $this->assertTrue($this->pmssMessagesContain($messages, 'path missing'));
    }

    public function testUpdateStep2PreflightKeepsFatalAndWarningOnlyContracts(): void
    {
        $home = $this->pmssMakeTempDir('pmss-preflight-disk-home-');
        $messages = [];
        $this->assertFalse(
            \pmssUpdateStep2PreflightChecks($this->pmssMakeArrayLogger($messages), [$home], [], [], '', 1.0E20),
            'Fatal disk-space failures should abort the caller'
        );
        $this->assertStringContainsAllStrings(['Insufficient free space on', 'Preflight checks failed (fatal)'], implode("\n", $messages));

        $messages = [];
        $missingBase = sys_get_temp_dir().'/pmss-preflight-missing-'.uniqid('', true);
        $this->assertTrue(
            \pmssUpdateStep2PreflightChecks($this->pmssMakeArrayLogger($messages), [], [$missingBase.'/dpkg.lock'], [$missingBase.'/apt-cache'], ''),
            'Warning-only preflight failures should not abort update-step2'
        );
        $this->assertStringContainsAllStrings(['Unable to open dpkg lock file', 'APT cache path missing or not writable'], implode("\n", $messages));
    }
}
