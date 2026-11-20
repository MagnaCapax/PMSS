<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

/**
 * NVMe devices run bfq like every other /home device class (ADR 0064 amendment 2026-11-20). On none the per-user
 * blkio.bfq.weight tiers and the bonus multiplier have nothing to act on, and cgroupBfqWeightApply.php FATALs on
 * every run. Separate file: BootTuningEnsureTest.php is past the split threshold.
 */
class BootTuningNvmeBfqTest extends TestCase
{
    protected function setUp(): void
    {
        $this->pmssTrackEnvOverrides(['PMSS_CONFIG_DIR' => $this->pmssRepoPath('etc/seedbox/config')], true);
    }

    public function testNvmeLoopWritesBfqAndHardwareJsonSaysSo(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-boot-tuning-nvme-', 0700);
        $script = $dir.'/sbin/pmss-boot-tuning.sh';
        $this->pmssArrayLoggerMessages(function (callable $logger) use ($dir, $script): void {
            \pmssEnsureBootTuning($logger, $script, $dir.'/systemd/pmss-boot-tuning.service');
        });
        $body = (string) file_get_contents($script);

        $this->assertSame(1, preg_match('#for disk in /sys/block/nvme\*; do\n(.*?)\ndone#s', $body, $m), 'the nvme loop is present');
        $this->assertStringContainsString('write_sys "$disk/queue/scheduler" bfq', $m[1], 'nvme devices are set to bfq');
        $this->pmssAssertStringNotContainsString('scheduler" none', $body, 'no device class is set to none');
        $this->assertStringContainsString('"nvme_scheduler": "bfq"', $body, 'hardware.json advertises what the unit writes');
    }
}
