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
