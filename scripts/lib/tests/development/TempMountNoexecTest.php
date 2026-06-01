<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/mountHardening.php';

class TempMountNoexecTest extends TestCase
{
    public function setUp(): void
    {
        $this->pmssTrackEnvKeys(['PMSS_HARDEN_TMP_NOEXEC', 'PMSS_DRY_RUN']);
    }

    private function runNoexecHardening(string $fstab, string $mounts, ?string $flag = '1'): array
    {
        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);
        putenv($flag === null ? 'PMSS_HARDEN_TMP_NOEXEC' : 'PMSS_HARDEN_TMP_NOEXEC='.$flag);
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempMountNoexec($logger, $fstab, $mounts);
        return $messages;
    }

    public function testSkipsWhenFlagDisabled(): void
    {
        foreach ([
            [null, 'disabled', 'pmss-noexec-skip-'],
            ['FALSE', 'disabled via PMSS_HARDEN_TMP_NOEXEC', 'pmss-noexec-false-'],
        ] as [$flag, $needle, $prefix]) {
            $original = "tmpfs /tmp tmpfs defaults,nosuid,nodev 0 0\n";
            $original .= "tmpfs /dev/shm tmpfs defaults,nosuid,nodev 0 0\n";
            ['fstab' => $fstab, 'mounts' => $mounts] = $this->pmssMountFixtureCreate(
                $prefix,
                $original,
                "tmpfs /tmp tmpfs rw,nosuid,nodev 0 0\n"
            );

            $messages = $this->runNoexecHardening($fstab, $mounts, $flag);
            $this->assertEquals($original, (string) file_get_contents($fstab));
            $this->pmssAssertMessagesContain($messages, $needle, 'expected disabled log');
        }
    }

    public function testAddsNoexecOptionsToFstab(): void
    {
        $original = "tmpfs /tmp tmpfs defaults,nosuid,nodev 0 0\n";
        $original .= "tmpfs /dev/shm tmpfs defaults,nosuid,nodev 0 0\n";
        ['fstab' => $fstab, 'mounts' => $mounts] = $this->pmssMountFixtureCreate(
            'pmss-noexec-add-',
            $original,
            "tmpfs /tmp tmpfs rw,nosuid,nodev 0 0\n".
            "tmpfs /dev/shm tmpfs rw,nosuid,nodev 0 0\n"
        );

        $messages = $this->runNoexecHardening($fstab, $mounts);

        $updated = (string)file_get_contents($fstab);
        $this->assertStringContainsAllStrings(['/tmp', '/dev/shm', 'noexec'], $updated);
        $this->pmssAssertMessagesContain($messages, 'Updated /tmp mount options', 'expected /tmp update log');
    }

    public function testRemovesConflictingOptions(): void
    {
        $original = "tmpfs /tmp tmpfs defaults,exec,suid,dev 0 0\n";
        ['fstab' => $fstab, 'mounts' => $mounts] = $this->pmssMountFixtureCreate(
            'pmss-noexec-conflict-',
            $original,
            "tmpfs /tmp tmpfs rw,exec,suid,dev 0 0\n"
        );

        $this->runNoexecHardening($fstab, $mounts);

        $this->pmssAssertFstabOptions($fstab, '/tmp', ['noexec', 'nosuid', 'nodev'], ['exec', 'suid', 'dev']);
    }

    public function testAlreadyHardenedSkips(): void
    {
        $original = "tmpfs /tmp tmpfs defaults,noexec,nosuid,nodev 0 0\n";
        ['fstab' => $fstab, 'mounts' => $mounts] = $this->pmssMountFixtureCreate(
            'pmss-noexec-skip-hardened-',
            $original,
            "tmpfs /tmp tmpfs rw,noexec,nosuid,nodev 0 0\n"
        );

        $messages = $this->runNoexecHardening($fstab, $mounts);

        $this->assertEquals($original, (string)file_get_contents($fstab));
        $this->pmssAssertMessagesContain($messages, 'already hardened', 'expected already hardened log');
    }

    public function testMountMissingLeavesFstabUntouched(): void
    {
        $original = "UUID=abc /home ext4 defaults 0 0\n";
        ['fstab' => $fstab, 'mounts' => $mounts] = $this->pmssMountFixtureCreate(
            'pmss-noexec-missing-',
            $original,
            "tmpfs /dev/shm tmpfs rw,nosuid,nodev 0 0\n"
        );

        $messages = $this->runNoexecHardening($fstab, $mounts);

        $this->assertEquals($original, (string)file_get_contents($fstab));
        $this->pmssAssertMessagesContain($messages, 'not found', 'expected not found log');
    }

    public function testUnreadableFstabWarns(): void
    {
        ['fstab' => $fstab, 'mounts' => $mounts] = $this->pmssMountFixtureCreate(
            'pmss-noexec-unreadable-',
            "tmpfs /tmp tmpfs defaults 0 0\n",
            "tmpfs /tmp tmpfs rw,nosuid,nodev 0 0\n"
        );
        chmod($fstab, 0000);

        $messages = $this->runNoexecHardening($fstab, $mounts);

        $this->pmssAssertMessagesContain($messages, 'not readable', 'expected not readable log');
        chmod($fstab, 0600);
    }

    public function testUnreadableFstabStillProfilesLiveRemounts(): void
    {
        ['fstab' => $fstab, 'mounts' => $mounts] = $this->pmssMountFixtureCreate(
            'pmss-noexec-unreadable-remount-',
            "tmpfs /tmp tmpfs defaults 0 0\n",
            "tmpfs /tmp tmpfs rw,exec,suid,dev 0 0\n"
        );
        chmod($fstab, 0000);

        $this->pmssResetRuntimeProfile();
        $messages = $this->runNoexecHardening($fstab, $mounts);

        $this->assertEquals(["mount '-o' 'remount,noexec,nosuid,nodev' '/tmp'"], $this->pmssProfileCommands());
        $this->pmssAssertMessagesContain($messages, 'not readable', 'expected not readable log');
        chmod($fstab, 0600);
    }

    public function testSymlinkedFstabWarnsAndSkips(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-noexec-symlinked-', 0700);
        $fstabTarget = $dir.'/fstab-target';
        $fstab = $dir.'/fstab';
        $mounts = $dir.'/mounts';

        $this->pmssWriteFile($fstabTarget, "tmpfs /tmp tmpfs defaults 0 0\n");
        $this->pmssCreateSymlinkOrSkip($fstabTarget, $fstab);
        $this->pmssWriteFile($mounts, "tmpfs /tmp tmpfs rw,nosuid,nodev 0 0\n");

        $messages = $this->runNoexecHardening($fstab, $mounts);

        $this->assertEquals("tmpfs /tmp tmpfs defaults 0 0\n", (string) file_get_contents($fstabTarget));
        $this->pmssAssertMessagesContain($messages, 'not a regular file', 'expected regular-file guard log');
    }

    public function testWriteFailureSkipsRemountsWhenFstabUpdateCannotPersist(): void
    {
        ['dir' => $dir, 'fstab' => $fstab, 'mounts' => $mounts] = $this->pmssMountFixtureCreate(
            'pmss-noexec-write-fail-',
            "tmpfs /tmp tmpfs defaults 0 0\nshm /dev/shm tmpfs defaults 0 0\n",
            "tmpfs /tmp tmpfs rw,exec,suid,dev 0 0\nshm /dev/shm tmpfs rw,exec,suid,dev 0 0\n"
        );
        chmod($dir, 0500);

        $this->pmssResetRuntimeProfile();
        $messages = $this->runNoexecHardening($fstab, $mounts);

        $this->assertEquals([], $this->pmssProfileCommands());
        $this->pmssAssertMessagesContain($messages, 'Failed writing updated '.$fstab, 'expected write failure log');
        $this->pmssAssertMessagesContain($messages, 'Skipping live mount hardening because '.$fstab.' could not be updated', 'expected remount skip log');

        chmod($dir, 0700);
    }

    public function testDryRunProfilesRemountCommandsInStableOrder(): void
    {
        ['fstab' => $fstab, 'mounts' => $mounts] = $this->pmssMountFixtureCreate(
            'pmss-noexec-profile-',
            "tmpfs /tmp tmpfs defaults 0 0\nshm /dev/shm tmpfs defaults 0 0\n",
            "tmpfs /tmp tmpfs rw,exec,suid,dev 0 0\nshm /dev/shm tmpfs rw,exec,suid,dev 0 0\n"
        );

        $this->pmssResetRuntimeProfile();
        putenv('PMSS_HARDEN_TMP_NOEXEC=1');
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempMountNoexec(null, $fstab, $mounts);

        $this->assertEquals([
            "mount '-o' 'remount,noexec,nosuid,nodev' '/tmp'",
            "mount '-o' 'remount,noexec,nosuid,nodev' '/dev/shm'",
        ], $this->pmssProfileCommands());
    }

}
