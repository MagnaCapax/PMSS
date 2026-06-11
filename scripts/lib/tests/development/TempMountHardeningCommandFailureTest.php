<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/mountHardening.php';

class TempMountHardeningCommandFailureTest extends TestCase
{
    public function setUp(): void
    {
        $this->pmssTrackEnvKeys([
            'PMSS_HARDEN_TMP_NOEXEC',
            'PMSS_HARDEN_TMP_TMPFS',
            'PMSS_DRY_RUN',
            'PATH',
        ]);
    }

    /** Create a PATH prefix where mount fails before any real system mount can run. */
    private function failingMountPath(): string
    {
        return $this->pmssMakeExecutableStub(
            'mount',
            "#!/bin/sh\nexit 17\n",
            'pmss-mount-hardening-fail-bin-'
        );
    }

    public function testNoexecRemountFailureIsLogged(): void
    {
        ['fstab' => $fstab, 'mounts' => $mounts] = $this->pmssMountFixtureCreate(
            'pmss-noexec-command-fail-',
            "tmpfs /tmp tmpfs defaults,noexec,nosuid,nodev 0 0\n",
            "tmpfs /tmp tmpfs rw,exec,suid,dev 0 0\n"
        );

        $this->pmssResetRuntimeProfile();
        $messages = [];
        $this->pmssWithPathPrefixedEnv(
            $this->failingMountPath(),
            ['PMSS_HARDEN_TMP_NOEXEC' => '1', 'PMSS_DRY_RUN' => null],
            function () use (&$messages, $fstab, $mounts): void {
                $messages = $this->pmssArrayLoggerMessages(
                    function (callable $logger) use ($fstab, $mounts): void {
                        \pmssConfigureTempMountNoexec($logger, $fstab, $mounts);
                    }
                );
            }
        );

        $this->assertEquals(["mount '-o' 'remount,noexec,nosuid,nodev' '/tmp'"], $this->pmssProfileCommands());
        $this->pmssAssertMessagesContain(
            $messages,
            'Mount hardening command failed (rc=17): Remounting /tmp with noexec hardening'
        );
    }

    public function testTmpfsMountFailureIsLogged(): void
    {
        ['fstab' => $fstab, 'mounts' => $mounts] = $this->pmssMountFixtureCreate(
            'pmss-tmpfs-command-fail-',
            "UUID=abc / ext4 defaults 0 0\n",
            ''
        );

        $this->pmssResetRuntimeProfile();
        $messages = [];
        $this->pmssWithPathPrefixedEnv(
            $this->failingMountPath(),
            ['PMSS_HARDEN_TMP_TMPFS' => '1', 'PMSS_DRY_RUN' => null],
            function () use (&$messages, $fstab, $mounts): void {
                $messages = $this->pmssArrayLoggerMessages(
                    function (callable $logger) use ($fstab, $mounts): void {
                        \pmssConfigureTempTmpfsMount($logger, $fstab, $mounts);
                    }
                );
            }
        );

        $this->assertEquals(["mount '/tmp'"], $this->pmssProfileCommands());
        $this->pmssAssertMessagesContain(
            $messages,
            'Mount hardening command failed (rc=17): Mounting /tmp tmpfs from fstab'
        );
    }
}
