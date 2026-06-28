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
