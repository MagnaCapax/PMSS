<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/cgroup/schedulerDrift.php';

/**
 * pmssCgroupPolicyMountDriftedLeaves names the disks under /home that the boot-tuning unit sets to bfq but that run
 * another elevator. The weight cron re-runs the unit when the list is not empty, so a runtime write of another
 * scheduler no longer switches every account's weight, bonus and paid I/O priority off until the next boot.
 */
class SchedulerDriftTest extends TestCase
{
    /** Fake /sys/block: [device => scheduler line], [md => members], [disk => partitions]. */
    private function sysBlock(array $devices, array $slaves = [], array $partitions = []): string
    {
        $root = $this->pmssMakeTempDir('pmss-sched-drift-', 0700);
        foreach ($devices as $device => $scheduler) {
            $this->pmssWriteFiles($root, [$device.'/queue/scheduler' => $scheduler."\n"], 0700);
        }
        foreach ($slaves as $device => $members) {
            foreach ($members as $member) $this->pmssEnsureDir($root.'/'.$device.'/slaves/'.$member, 0700);
        }
        foreach ($partitions as $disk => $members) {
            foreach ($members as $member) $this->pmssWriteFiles($root, [$disk.'/'.$member.'/partition' => "1\n"], 0700);
        }
        return $root;
    }

    /** The 2026-11-19 shape: a guest's /home disk switched to none at runtime. */
    public function testGuestHomeDiskOnNoneIsDrift(): void
    {
        $root = $this->sysBlock(['sda' => 'mq-deadline [bfq] none', 'vda' => 'mq-deadline bfq [none]']);
        $this->assertSame(['vda'], pmssCgroupPolicyMountDriftedLeaves('/home', '/dev/vda', $root));
    }

    /** One md member of six off bfq: the any-leaf check still reads active, the drift list names the member. */
    public function testOneMdMemberOffBfqIsDriftWhileAnyLeafCheckStaysGreen(): void
    {
        $devices = ['md2' => '[none]'];
        foreach (['sda', 'sdb', 'sdc', 'sdd', 'sde', 'sdf'] as $disk) $devices[$disk] = 'mq-deadline [bfq] none';
        $devices['sdc'] = 'mq-deadline bfq [none]';
        $root = $this->sysBlock($devices, ['md2' => ['sda', 'sdb', 'sdc', 'sdd', 'sde', 'sdf']]);
        $this->assertTrue(pmssCgroupPolicyMountBfqActive('/home', '/dev/md2', $root), 'the existing check passes on any bfq leaf');
        $this->assertSame(['sdc'], pmssCgroupPolicyMountDriftedLeaves('/home', '/dev/md2', $root));
    }

    /** Partition members resolve to their disks, as on md arrays built from sdX1. */
    public function testPartitionMemberDriftIsReportedOnTheParentDisk(): void
    {
        $root = $this->sysBlock(
            ['md5' => '[none]', 'sda' => 'mq-deadline [bfq] none', 'sdb' => 'mq-deadline bfq [none]'],
            ['md5' => ['sda1', 'sdb1']],
            ['sda' => ['sda1'], 'sdb' => ['sdb1']]
        );
        $this->assertSame(['sdb'], pmssCgroupPolicyMountDriftedLeaves('/home', '/dev/md5', $root));
    }

    /** NVMe runs bfq as well (ADR 0064 amendment 2026-11-20): an NVMe member left on none is drift like any other. */
    public function testNvmeMemberOnNoneIsDrift(): void
    {
        $root = $this->sysBlock(
            ['md1' => '[none]', 'nvme0n1' => 'mq-deadline [bfq] none', 'nvme1n1' => '[none] mq-deadline bfq'],
            ['md1' => ['nvme0n1', 'nvme1n1']]
        );
        $this->assertSame(['nvme1n1'], pmssCgroupPolicyMountDriftedLeaves('/home', '/dev/md1', $root));
    }

    public function testAllMembersOnBfqIsNoDrift(): void
    {
        $root = $this->sysBlock(
            ['md2' => '[none]', 'sda' => 'mq-deadline [bfq] none', 'sdb' => 'mq-deadline [bfq] none'],
            ['md2' => ['sda', 'sdb']]
        );
        $this->assertSame([], pmssCgroupPolicyMountDriftedLeaves('/home', '/dev/md2', $root));
    }

    /** Unknown topology is not drift and not health: null, so the cron never re-runs the unit on a guess. */
    public function testUnreadableTopologyReturnsNull(): void
    {
        $root = $this->sysBlock(['sda' => 'mq-deadline [bfq] none']);
        $this->assertSame(null, pmssCgroupPolicyMountDriftedLeaves('/home', '/dev/vdz', $root));
    }

    public function testNulInputsCannotBecomeAValidMountOrDevice(): void
    {
        $root = $this->sysBlock(['vda' => 'mq-deadline bfq [none]']);
        foreach (["\0/dev/vda", "/dev/vda\0", "/dev/\0/vda", "/dev/vd\0a", " \0/dev/vda\0\n"] as $source) {
            $this->pmssAssertNoPhpWarnings(function () use ($root, $source): void {
                $this->assertSame(null, pmssCgroupPolicyMountDriftedLeaves('/home', $source, $root));
            });
        }
        foreach (["\0/home", "/home\0", "/ho\0me", " \0/home\0\n", "\0"] as $mount) {
            $this->pmssAssertNoPhpWarnings(function () use ($root, $mount): void {
                $this->assertSame(null, pmssCgroupPolicyMountDriftedLeaves($mount, null, $root));
            });
        }
        // An explicit source still leaves the mount path unused; whitespace normalization remains.
        $this->assertSame(['vda'], pmssCgroupPolicyMountDriftedLeaves("/unused\0mount", " /dev/vda\n", $root));
    }

    public function testUnusableSchedulerReadsCannotCertifyEvenAPartialDriftList(): void
    {
        foreach (['', " \t\r\n", "\0", "\0[bfq] none", "[bfq] none\0", "[b\0fq] none",
            "mq-deadline bfq [none]\0", "mq-deadline\0 bfq [none]"] as $scheduler) {
            $root = $this->sysBlock(['md2' => '[none]', 'sda' => '[none] bfq', 'sdb' => $scheduler],
                ['md2' => ['sda', 'sdb']]);
            $this->pmssAssertNoPhpWarnings(function () use ($root): void {
                $this->assertSame(null, pmssCgroupPolicyMountDriftedLeaves('/home', '/dev/sdb', $root));
                $this->assertSame(null, pmssCgroupPolicyMountDriftedLeaves('/home', '/dev/md2', $root));
            });
        }
        // Keep valid scheduler whitespace and the existing healthy-versus-drift distinction.
        $root = $this->sysBlock(['sda' => " \t[bfq] none ", 'sdb' => " \tbfq [none] "]);
        $this->assertSame([], pmssCgroupPolicyMountDriftedLeaves('/home', '/dev/sda', $root));
        $this->assertSame(['sdb'], pmssCgroupPolicyMountDriftedLeaves('/home', '/dev/sdb', $root));
    }
}
