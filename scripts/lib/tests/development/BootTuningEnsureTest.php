<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class BootTuningEnsureTest extends TestCase
{
    protected function setUp(): void
    {
        $this->pmssTrackEnvOverrides(['PMSS_CONFIG_DIR' => $this->pmssRepoPath('etc/seedbox/config')], true);
    }

    public function testWritesBootTuningScript(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-boot-tuning-script-', 0700);
        [$script, $service] = $this->runBootTuning($dir);

        $this->pmssAssertFileContainsAllStrings($script, [
            '/sys/kernel/mm/lru_gen/enabled', '/sys/module/zswap/parameters/enabled', '/md/stripe_cache_size',
            'target_file="$target_dir/hardware.json"', '"swap_is_fast":', '"nic_speed_mbps":',
        ], 'expected boot tuning script to be written');
        $this->assertTrue(file_exists($service), 'expected boot tuning service to be written');
    }

    public function testTunesVirtioBlkDataDisks(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-boot-tuning-virtio-', 0700);
        [$script] = $this->runBootTuning($dir);

        $this->pmssAssertFileContainsAllStrings($script, [
            '/sys/block/vd*',
            '/sys/block/xvd*',
        ], 'expected boot tuning to cover virtio-blk disks: /home is on /dev/vda on every PMSS KVM guest, '
            .'so an sd*-only selector leaves all customer I/O unscheduled and makes the per-user '
            .'blkio.bfq.weight tiers set by cron/cgroupBfqWeightApply.php inert');
    }

    public function testNoWriterDivergesFromRcLocalReadAhead(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-boot-tuning-ra-all-', 0700);
        [$script] = $this->runBootTuning($dir);

        // template.rc.local writes a blanket 4096 to every non-nvme non-md device (sd*, vd*,
        // xvd*, bcache*) and runs LATER than this unit (rc-local is After=network-online.target,
        // this unit is After=local-fs.target), so it wins on every overlapping device. Any other
        // value written here is not a policy - it is a value overwritten a second later. nvme is
        // the one legitimate exception: rc.local's loop excludes it, so 128 is uncontested.
        // See ADR 0064.
        $body = (string)file_get_contents($script);
        foreach (['2048', '1024', '512'] as $stale) {
            $this->pmssAssertStringNotContainsString(
                'read_ahead_kb" '.$stale,
                $body,
                'read_ahead_kb '.$stale.' diverges from template.rc.local 4096 on a device class '
                .'rc.local also writes; rc.local runs later and wins (ADR 0064)'
            );
        }
    }

    public function testMdReadAheadMatchesRcLocal(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-boot-tuning-md-ra-', 0700);
        [$script] = $this->runBootTuning($dir);

        // Same two-writer shape as the virtio case below, on the md branch: this service
        // and template.rc.local both write /sys/block/<md>/queue/read_ahead_kb, so a
        // divergent value makes the effective setting depend on boot order. rc.local
        // writes 4096 (operator ruling 2026-09-18: "4096 is more authoritative"), so the
        // md branch and the hardware.json md_read_ahead_kb it advertises must both be 4096.
        $this->pmssAssertFileContainsAllStrings($script, [
            '"md_read_ahead_kb": 4096',
        ], 'hardware.json must advertise the md read_ahead_kb this script actually writes; '
            .'a stale declaration makes the audit surface lie about the host');

        $this->pmssAssertStringNotContainsString(
            'read_ahead_kb" 2048',
            (string)file_get_contents($script),
            'no writer may diverge from template.rc.local 4096 on a knob rc.local also writes'
        );
    }

    public function testRcLocalPerDiskLoopGatedOnBootTuningPresence(): void
    {
        // ADR 0064 step 2. On a host carrying pmss-boot-tuning.service, boot-tuning is the sole
        // writer of sd*/vd*/bcache* queue knobs; template.rc.local's per-disk loop must be gated
        // on the unit's ABSENCE (one writer on updated hosts, still-tuned on un-updated hosts).
        // The gate must PRECEDE the per-disk (DISK) loop.
        $rel = 'etc/seedbox/config/template.rc.local';
        $this->pmssAssertRepoFileContainsOrderedStrings(
            $rel,
            ['if [ ! -e /usr/local/sbin/pmss-boot-tuning.sh ]; then', 'for DISK in'],
            'rc.local must gate the per-disk loop on boot-tuning presence (ADR 0064 step 2)',
            'the boot-tuning presence gate must appear before the per-disk (DISK) loop'
        );
        // The md stripe_cache loop and the bcache cache_mode loop are NOT gated - boot-tuning does
        // not own those - so they must remain present in rc.local.
        $this->pmssAssertRepoFileContainsAllStrings(
            $rel,
            ['stripe_cache_size', 'bcache/cache_mode'],
            'md loop and bcache cache_mode loop must remain in rc.local (step 2 gates only per-disk)'
        );
    }

    public function testSdSchedulerStaysBfqNotMqDeadline(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-boot-tuning-sd-sched-', 0700);
        [$script] = $this->runBootTuning($dir);
        $body = (string)file_get_contents($script);

        // ADR 0064 step 2. Gating rc.local's per-disk loop makes THIS unit the sole writer of sd*
        // queue knobs on updated hosts. rc.local's blanket loop wrote bfq to every sd*, so this
        // unit must write bfq too - otherwise gating the loop silently flips non-rotational sd* to
        // mq-deadline fleet-wide: an un-measured change the ADR defers, and one that breaks the
        // per-user blkio.bfq.weight fairness (mq-deadline cannot honor those weights;
        // cgroupBfqWeightApply.php / CgroupPolicyHomeBfqTest depend on bfq being active on /home).
        $this->pmssAssertStringNotContainsString(
            'scheduler" mq-deadline',
            $body,
            'this unit must not write mq-deadline for sd*: rc.local wrote bfq, so gating rc.local\'s '
            .'per-disk loop (step 2) would flip SSDs to mq-deadline and break blkio.bfq.weight fairness. '
            .'The mq-deadline-on-SSD question is a separate measured decision (ADR 0064), not a consolidation side effect'
        );
        $this->pmssAssertFileContainsAllStrings($script, [
            '"nonrotational_scheduler": "bfq"',
        ], 'hardware.json must advertise the behavior-preserving bfq this unit writes for non-rotational sd*');
    }

    public function testBcacheBranchCoversRcLocalQueueKnobsAndNotCacheMode(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-boot-tuning-bcache-', 0700);
        [$script] = $this->runBootTuning($dir);
        $body = (string)file_get_contents($script);

        // ADR 0064 step 1. template.rc.local's blanket per-disk loop
        // (ls /sys/block|grep -v nvme|grep -v md|grep -v loop) INCLUDES bcache*, so until this
        // branch existed rc.local was the only queue-knob writer for those devices. Step 2 gates
        // that loop off on updated hosts; without this branch the gating would silently drop
        // bcache tuning fleet-wide. Assert the branch is here so the ordering cannot be inverted.
        $this->pmssAssertFileContainsAllStrings($script, [
            '/sys/block/bcache*',
            '"bcache_read_ahead_kb": 4096',
            '"bcache_scheduler": "bfq"',
        ], 'bcache* needs a queue-knob branch here plus a matching hardware.json declaration '
            .'before rc.local\'s per-disk loop can be gated off (ADR 0064 step 1); a missing '
            .'declaration also makes the audit surface lie about the host');

        // Scope fence, asserted rather than merely commented: the cache MODE knobs come from a
        // SEPARATE rc.local loop, not the blanket per-disk one, so they are outside step 1 and
        // survive step 2 untouched. Hosts are actively being moved off writeback, so adopting
        // that mode here would freeze a live policy decision into this unit as a side effect.
        // Assert the sysfs PATH form, not the bare word: the mode knobs live under
        // /sys/block/bcacheN/bcache/ while the queue knobs this unit owns live under
        // /sys/block/bcacheN/queue/. Matching the bare word would fire on the comment above
        // that explains the fence - a substring standing in for a shape (catalog #1).
        foreach (['bcache/cache_mode', 'bcache/writeback_percent', 'bcache/writeback_delay'] as $modeKnob) {
            $this->pmssAssertStringNotContainsString(
                $modeKnob,
                $body,
                'this unit tunes bcache QUEUE knobs only; '.$modeKnob.' belongs to template.rc.local\'s '
                .'separate bcache loop and must not be adopted here (ADR 0064 step 1 scope fence)'
            );
        }
    }

    public function testVirtioReadAheadMatchesRcLocal(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-boot-tuning-ra-', 0700);
        [$script] = $this->runBootTuning($dir);

        // Two shipped paths write this same knob on a virtio-blk guest: this service and
        // template.rc.local. Whichever runs last wins, so a mismatch makes the effective
        // read_ahead_kb non-deterministic. Measured live 2026-09-18: a node verified at
        // 4096 held 2048 twenty-eight minutes later. Pin them equal so the drift cannot
        // return silently.
        $this->pmssAssertFileContainsAllStrings($script, [
            'read_ahead_kb" 4096',
        ], 'virtio-blk read_ahead_kb must match template.rc.local (4096); a divergent value '
            .'makes the effective setting depend on which writer ran last');
    }
    public function testWritesBootTuningService(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-boot-tuning-service-', 0700);
        [$script, $service] = $this->runBootTuning($dir);

        $this->pmssAssertFileContainsAllStrings($service, [
            'ExecStart='.$script,
            'WantedBy=multi-user.target',
        ], 'expected systemd service to be written');
    }

    public function testScriptPermissionsAreExecutable(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-boot-tuning-perms-', 0700);
        [$script, $service] = $this->runBootTuning($dir);

        $perms = fileperms($script) & 0777;
        $this->assertEquals(0755, $perms, 'expected boot tuning script to be executable');
        $this->assertTrue(file_exists($service), 'expected service to exist for permissions test');
    }

    public function testCreatesTargetDirectories(): void
    {
        $base = $this->pmssMakeTempDir('pmss-boot-tuning-dirs-', 0700);
        $script = $base.'/nested/sbin/pmss-boot-tuning.sh';
        $service = $base.'/nested/systemd/pmss-boot-tuning.service';

        $this->pmssArrayLoggerMessages(function (callable $logger) use ($script, $service): void {
            \pmssEnsureBootTuning($logger, $script, $service);
        });

        $this->assertTrue(is_dir(dirname($script)), 'expected script directory to be created');
        $this->assertTrue(is_dir(dirname($service)), 'expected service directory to be created');
    }

    public function testSkipsWhenUpToDate(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-boot-tuning-skip-', 0700);
        $this->runBootTuning($dir);

        [, , $messages] = $this->runBootTuning($dir);

        $this->pmssAssertMessagesContain($messages, 'Boot tuning script already present and up to date');
        $this->pmssAssertMessagesContain($messages, 'Boot tuning service already present and up to date');
    }

    /**
     * @return array{0:string,1:string,2:array<int,string>}
     */
    private function runBootTuning(string $dir): array
    {
        $script = $dir.'/sbin/pmss-boot-tuning.sh';
        $service = $dir.'/systemd/pmss-boot-tuning.service';
        return [$script, $service, $this->pmssArrayLoggerMessages(function (callable $logger) use ($script, $service): void {
            \pmssEnsureBootTuning($logger, $script, $service);
        })];
    }

}
