<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/mountHardening.php';

class TempTmpfsMountTest extends TestCase
{
    public function setUp(): void
    {
        $this->pmssTrackEnvKeys(['PMSS_HARDEN_TMP_TMPFS', 'PMSS_TMPFS_TMP_SIZE', 'PMSS_DRY_RUN']);
    }

    private function runTmpfsHardening(string $fstab, string $mounts, ?string $flag = '1', ?string $size = null): array
    {
        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);
        putenv($flag === null ? 'PMSS_HARDEN_TMP_TMPFS' : 'PMSS_HARDEN_TMP_TMPFS='.$flag);
        $size !== null && putenv('PMSS_TMPFS_TMP_SIZE='.$size);
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempTmpfsMount($logger, $fstab, $mounts);
        return $messages;
    }

    public function testSkipsWhenFlagDisabled(): void
    {
        foreach ([
            [null, 'disabled', 'pmss-tmpfs-skip-'],
            ['FALSE', 'disabled via PMSS_HARDEN_TMP_TMPFS', 'pmss-tmpfs-false-'],
        ] as [$flag, $needle, $prefix]) {
            $original = "UUID=abc / ext4 defaults 0 0\n";
            ['fstab' => $fstab, 'mounts' => $mounts] = $this->pmssMountFixtureCreate($prefix, $original);

            $messages = $this->runTmpfsHardening($fstab, $mounts, $flag);
            $this->assertEquals($original, (string) file_get_contents($fstab));
            $this->assertTrue($this->pmssMessagesContain($messages, $needle), 'expected disabled log');
        }
    }

    public function testAddsTmpfsEntryWhenMissing(): void
    {
        foreach ([[null, '2G'], ['512M', '512M']] as [$override, $expectedSize]) {
            $original = "UUID=abc / ext4 defaults 0 0\n";
            ['fstab' => $fstab, 'mounts' => $mounts] = $this->pmssMountFixtureCreate('pmss-tmpfs-add-', $original);

            $messages = $this->runTmpfsHardening($fstab, $mounts, '1', $override);

            $this->assertStringContainsString('tmpfs /tmp tmpfs defaults,noexec,nosuid,nodev,size='.$expectedSize.' 0 0', (string)file_get_contents($fstab));
            $this->pmssAssertMessagesContain($messages, $override === null ? 'Added /tmp tmpfs entry' : 'size='.$expectedSize);
        }
    }

    public function testSkipsWhenNonTmpfsEntryExists(): void
    {
        $original = "UUID=abc /tmp ext4 defaults 0 0\n";
        ['fstab' => $fstab, 'mounts' => $mounts] = $this->pmssMountFixtureCreate('pmss-tmpfs-nontmp-', $original);

        $messages = $this->runTmpfsHardening($fstab, $mounts);

        $this->assertEquals($original, (string)file_get_contents($fstab));
        $this->assertTrue($this->pmssMessagesContain($messages, 'non-tmpfs'), 'expected non-tmpfs log');
    }

    public function testUpdatesTmpfsEntryOptions(): void
    {
        $original = "tmpfs /tmp tmpfs defaults,exec,suid,dev,size=1G 0 0\n";
        ['fstab' => $fstab, 'mounts' => $mounts] = $this->pmssMountFixtureCreate(
            'pmss-tmpfs-update-',
            $original,
            "tmpfs /tmp tmpfs rw,exec,suid,dev 0 0\n"
        );

        $messages = $this->runTmpfsHardening($fstab, $mounts);

        $this->pmssAssertFstabOptions($fstab, '/tmp', ['noexec', 'nosuid', 'nodev', 'size=2G'], ['exec', 'suid', 'dev']);
        $this->assertTrue($this->pmssMessagesContain($messages, 'Updated /tmp tmpfs options'), 'expected update log');
    }

    public function testUnreadableMountsWarns(): void
    {
        ['fstab' => $fstab, 'mounts' => $mounts] = $this->pmssMountFixtureCreate(
            'pmss-tmpfs-mounts-unreadable-',
            "tmpfs /tmp tmpfs defaults 0 0\n",
            "tmpfs /tmp tmpfs rw 0 0\n"
        );
        chmod($mounts, 0000);

        $messages = $this->runTmpfsHardening($fstab, $mounts);

        $this->assertTrue($this->pmssMessagesContain($messages, 'not readable'), 'expected not readable log');
        chmod($mounts, 0600);
    }

    public function testWriteFailureSkipsTmpfsRemountWhenFstabUpdateCannotPersist(): void
    {
        ['dir' => $dir, 'fstab' => $fstab, 'mounts' => $mounts] = $this->pmssMountFixtureCreate(
            'pmss-tmpfs-write-fail-',
            "tmpfs /tmp tmpfs defaults,exec,suid,dev,size=1G 0 0\n",
            "tmpfs /tmp tmpfs rw,exec,suid,dev 0 0\n"
        );
        chmod($dir, 0500);

        $this->pmssResetRuntimeProfile();
        $messages = $this->runTmpfsHardening($fstab, $mounts);

        $this->assertEquals([], $this->pmssProfileCommands());
        $this->assertTrue($this->pmssMessagesContain($messages, 'Failed writing updated '.$fstab), 'expected write failure log');
        $this->assertTrue($this->pmssMessagesContain($messages, 'Skipping live mount hardening because '.$fstab.' could not be updated'), 'expected remount skip log');

        chmod($dir, 0700);
    }

    public function testDryRunProfilesTmpfsRemountCommand(): void
    {
        ['fstab' => $fstab, 'mounts' => $mounts] = $this->pmssMountFixtureCreate(
            'pmss-tmpfs-profile-',
            "tmpfs /tmp tmpfs defaults,exec,suid,dev,size=1G 0 0\n",
            "tmpfs /tmp tmpfs rw,exec,suid,dev 0 0\n"
        );

        $this->pmssResetRuntimeProfile();
        putenv('PMSS_HARDEN_TMP_TMPFS=1');
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempTmpfsMount(null, $fstab, $mounts);

        $this->assertEquals([
            "mount '-o' 'remount,noexec,nosuid,nodev,size=2G' '/tmp'",
        ], $this->pmssProfileCommands());
    }

}
