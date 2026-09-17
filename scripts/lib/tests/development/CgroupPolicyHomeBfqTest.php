<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/cgroup/policy.php';

/**
 * pmssCgroupPolicyMountBfqActive answers "does BFQ govern /home's I/O?", which is the
 * only question that makes per-user blkio.bfq.weight values meaningful.
 *
 * The predecessor globbed /sys/block/sd* and so passed on a virtio-blk guest's 35 GB
 * boot disk while /home on vda ran unscheduled — a false green that hid inert weights.
 * These cases pin the two real fleet topologies plus the two failure modes.
 */
class CgroupPolicyHomeBfqTest extends TestCase
{
    /** Build a fake /sys/block tree: [device => scheduler-or-null], plus optional slaves. */
    private function fakeSysBlock(array $devices, array $slaves = []): string
    {
        $root = $this->pmssMakeTempDir('pmss-home-bfq-', 0700);
        foreach ($devices as $device => $scheduler) {
            @mkdir($root.'/'.$device.'/queue', 0700, true);
            if ($scheduler !== null) {
                file_put_contents($root.'/'.$device.'/queue/scheduler', $scheduler."\n");
            }
        }
        foreach ($slaves as $device => $members) {
            @mkdir($root.'/'.$device.'/slaves', 0700, true);
            foreach ($members as $member) {
                @mkdir($root.'/'.$device.'/slaves/'.$member, 0700, true);
            }
        }
        return $root;
    }

    /** Virtio-blk guest, tuned: /home is vda and vda itself carries BFQ. */
    public function testVirtioGuestWithBfqOnDataDiskIsActive(): void
    {
        $root = $this->fakeSysBlock([
            'sda' => 'mq-deadline [bfq] none',
            'vda' => 'mq-deadline [bfq] none',
        ]);

        $this->assertTrue(
            pmssCgroupPolicyMountBfqActive('/home', '/dev/vda', $root),
            'BFQ on the vda backing /home must read as active'
        );
    }

    /**
     * The regression this exists for: the boot disk runs BFQ, /home on vda does not.
     * The old sd*-glob returned true here; the answer must be false.
     */
    public function testVirtioGuestWithUnscheduledDataDiskIsNotActiveEvenWhenBootDiskHasBfq(): void
    {
        $root = $this->fakeSysBlock([
            'sda' => 'mq-deadline [bfq] none',
            'vda' => '[none] mq-deadline bfq',
        ]);

        $this->assertFalse(
            pmssCgroupPolicyMountBfqActive('/home', '/dev/vda', $root),
            'an unscheduled /home must NOT be reported active just because the boot disk has BFQ'
        );
    }

    /**
     * Bare metal: /home is an md array. md is a stacking driver with no elevator and
     * reports scheduler "none" by design, so the members carry BFQ. Reading md's own
     * scheduler here would FATAL every bare-metal host.
     */
    public function testMdBackedHomeChecksArrayMembersNotTheArray(): void
    {
        $root = $this->fakeSysBlock([
            'md1' => 'none',
            'sda' => 'mq-deadline [bfq] none',
            'sdb' => 'mq-deadline [bfq] none',
        ], ['md1' => ['sda', 'sdb']]);

        $this->assertTrue(
            pmssCgroupPolicyMountBfqActive('/home', '/dev/md1', $root),
            'md reports scheduler none by design; BFQ on its members must read as active'
        );
    }

    /** Members genuinely unscheduled -> false, not a masked pass. */
    public function testMdBackedHomeWithUnscheduledMembersIsNotActive(): void
    {
        $root = $this->fakeSysBlock([
            'md1' => 'none',
            'sda' => '[none] mq-deadline bfq',
        ], ['md1' => ['sda']]);

        $this->assertFalse(
            pmssCgroupPolicyMountBfqActive('/home', '/dev/md1', $root),
            'md members without BFQ must read as inactive'
        );
    }

    /**
     * Unknown topology returns null, never false — the caller falls back to the legacy
     * probe, so an unreadable layout can never become a new fleet-wide FATAL.
     */
    public function testUnknownTopologyReturnsNullSoCallersCanFallBack(): void
    {
        $root = $this->fakeSysBlock(['vda' => 'mq-deadline [bfq] none']);

        $this->assertSame(
            null,
            pmssCgroupPolicyMountBfqActive('/home', '/dev/doesnotexist', $root),
            'an absent device must be undeterminable, not a definitive failure'
        );
        $this->assertSame(
            null,
            pmssCgroupPolicyMountBfqActive('/home', '', $root),
            'an unresolvable mount source must be undeterminable, not a definitive failure'
        );
    }

    /** A device present but exposing no scheduler file is undeterminable, not failed. */
    public function testDeviceWithoutSchedulerFileReturnsNull(): void
    {
        $root = $this->fakeSysBlock(['vda' => null]);

        $this->assertSame(
            null,
            pmssCgroupPolicyMountBfqActive('/home', '/dev/vda', $root),
            'a device with no scheduler file must be undeterminable'
        );
    }

    /** Path-shaped junk in the mount source must never escape /sys/block. */
    public function testMaliciousMountSourceIsRejected(): void
    {
        $root = $this->fakeSysBlock(['vda' => 'mq-deadline [bfq] none']);

        foreach (['/dev/../../etc/passwd', '/dev/vd a', '/dev/vda;reboot'] as $source) {
            $this->assertSame(
                null,
                pmssCgroupPolicyMountBfqActive('/home', $source, $root),
                'unsafe mount source must be rejected: '.json_encode($source)
            );
        }
    }

    /**
     * findmnt emits a trailing newline, so surrounding whitespace must be tolerated,
     * not treated as a hostile token — rejecting it would break the real call path.
     */
    public function testTrailingWhitespaceFromFindmntIsTolerated(): void
    {
        $root = $this->fakeSysBlock(['vda' => 'mq-deadline [bfq] none']);

        $this->assertTrue(
            pmssCgroupPolicyMountBfqActive('/home', "/dev/vda\n", $root),
            "a mount source with findmnt's trailing newline must still resolve"
        );
    }
}
