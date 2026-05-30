<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/distro.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class TempDiskBackedMountTest extends TestCase
{
    public function testSkipsBeforeDebian13(): void
    {
        $messages = [];
        \pmssConfigureTempDiskBackedMount($this->pmssMakeArrayLogger($messages), 12);

        $this->assertTrue($this->pmssMessagesContain($messages, 'Leaving /tmp mount policy unchanged'));
    }

    public function testMasksTmpMountOnDebian13(): void
    {
        $logPath = $this->pmssMakeTempPath('pmss-tmp-mask-', '.log');
        $binDir = $this->pmssMakeInvocationLogStub('systemctl', $logPath, 'pmss-tmp-mask-bin-');

        $messages = [];
        $this->pmssWithPathPrefix($binDir, function () use (&$messages): void {
            \pmssConfigureTempDiskBackedMount($this->pmssMakeArrayLogger($messages), 13);
        });

        $this->assertEquals("mask tmp.mount\n", (string) file_get_contents($logPath));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Masked tmp.mount'));
    }

    public function testMasksTmpMountOnLaterDebianVersions(): void
    {
        $logPath = $this->pmssMakeTempPath('pmss-tmp-mask-later-', '.log');
        $binDir = $this->pmssMakeInvocationLogStub('systemctl', $logPath, 'pmss-tmp-mask-later-bin-');

        $this->pmssWithPathPrefix($binDir, function (): void {
            \pmssConfigureTempDiskBackedMount(null, 14);
        });

        $this->assertEquals("mask tmp.mount\n", (string) file_get_contents($logPath));
    }

    public function testWarnsWhenSystemctlMissing(): void
    {
        $root = $this->pmssMakeTempDir('pmss-tmp-missing-', 0700);
        $messages = [];
        $this->pmssWithEnv(['PATH' => $root], function () use (&$messages): void {
            \pmssConfigureTempDiskBackedMount($this->pmssMakeArrayLogger($messages), 13);
        });

        $this->assertTrue($this->pmssMessagesContain($messages, 'systemctl unavailable'));
    }

    public function testDetectsDebian13FromOsReleaseWhenVersionMissing(): void
    {
        $logPath = $this->pmssMakeTempPath('pmss-tmp-detect-', '.log');
        $binDir = $this->pmssMakeInvocationLogStub('systemctl', $logPath, 'pmss-tmp-detect-bin-');

        $this->pmssWithOsRelease(['ID' => 'debian', 'VERSION_ID' => '12', 'VERSION_CODENAME' => 'trixie'], function () use ($binDir): void {
            $this->pmssWithPathPrefix($binDir, function (): void {
                \pmssConfigureTempDiskBackedMount();
            });
        });

        $this->assertEquals("mask tmp.mount\n", (string) file_get_contents($logPath));
    }

}
