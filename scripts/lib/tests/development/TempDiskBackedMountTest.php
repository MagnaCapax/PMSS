<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/FilesystemCleanupTrait.php';
require_once dirname(__DIR__, 2).'/update/distro.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class TempDiskBackedMountTest extends TestCase
{
    use FilesystemCleanupTrait;

    public function testSkipsBeforeDebian13(): void
    {
        $messages = [];
        \pmssConfigureTempDiskBackedMount($this->pmssMakeArrayLogger($messages), 12);

        $this->assertTrue($this->pmssMessagesContain($messages, 'Leaving /tmp mount policy unchanged'));
    }

    public function testMasksTmpMountOnDebian13(): void
    {
        $root = $this->pmssMakeTempDir('pmss-tmp-mask-', 0700);
        $binDir = $root.'/bin';
        $logPath = $root.'/systemctl.log';
        @mkdir($binDir, 0755, true);
        @file_put_contents($binDir.'/systemctl', "#!/bin/sh\nprintf '%s\n' \"$*\" >>".escapeshellarg($logPath)."\nexit 0\n");
        @chmod($binDir.'/systemctl', 0755);

        $messages = [];
        $this->pmssWithPathPrefix($binDir, function () use (&$messages): void {
            \pmssConfigureTempDiskBackedMount($this->pmssMakeArrayLogger($messages), 13);
        });

        $this->assertEquals("mask tmp.mount\n", (string) file_get_contents($logPath));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Masked tmp.mount'));
    }

    public function testMasksTmpMountOnLaterDebianVersions(): void
    {
        $root = $this->pmssMakeTempDir('pmss-tmp-mask-later-', 0700);
        $binDir = $root.'/bin';
        $logPath = $root.'/systemctl.log';
        @mkdir($binDir, 0755, true);
        @file_put_contents($binDir.'/systemctl', "#!/bin/sh\nprintf '%s\n' \"$*\" >>".escapeshellarg($logPath)."\nexit 0\n");
        @chmod($binDir.'/systemctl', 0755);

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
        $root = $this->pmssMakeTempDir('pmss-tmp-detect-', 0700);
        $binDir = $root.'/bin';
        $logPath = $root.'/systemctl.log';
        $osRelease = $root.'/os-release';
        @mkdir($binDir, 0755, true);
        @file_put_contents($binDir.'/systemctl', "#!/bin/sh\nprintf '%s\n' \"$*\" >>".escapeshellarg($logPath)."\nexit 0\n");
        @chmod($binDir.'/systemctl', 0755);
        @file_put_contents($osRelease, "ID=debian\nVERSION_ID=12\nVERSION_CODENAME=trixie\n");

        $previous = getenv('PMSS_OS_RELEASE_PATH');
        putenv('PMSS_OS_RELEASE_PATH='.$osRelease);

        try {
            $this->pmssWithPathPrefix($binDir, function (): void {
                \pmssConfigureTempDiskBackedMount();
            });
        } finally {
            $this->pmssRestoreEnv('PMSS_OS_RELEASE_PATH', $previous);
        }

        $this->assertEquals("mask tmp.mount\n", (string) file_get_contents($logPath));
    }

}
