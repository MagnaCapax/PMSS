<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class SysctlBaselineTest extends TestCase
{
    public function testWritesBaselineWithKptrRestrict(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-sysctl-', 0700);
        $target = $dir.'/sysctl.conf';
        $configDir = $dir.'/config';
        $this->pmssTrackEnvOverrides([
            'PMSS_CONFIG_DIR' => $configDir,
            'PMSS_TOTAL_MEM_MIB' => '262144',
            'PMSS_SYSCTL_HAS_SWAP' => '1',
            'PMSS_SYSCTL_SWAP_IS_FAST' => '1',
            'PMSS_SYSCTL_NIC_SPEED_MBPS' => '10000',
            'PMSS_SYSCTL_IS_VM' => '0',
            'PMSS_SYSCTL_HAS_CONNTRACK' => '1',
        ]);
        $messages = [];
        $this->runBaseline($target, $messages, false);

        $this->pmssAssertFileContainsAllStrings($target, [
            '# Pulsed Media Config', 'vm.swappiness = 100', 'vm.vfs_cache_pressure = 2', 'vm.min_free_kbytes = 2621440',
            'net.core.rmem_max = 67108864', 'net.core.default_qdisc = fq', 'net.ipv4.tcp_congestion_control = bbr',
            'net.netfilter.nf_conntrack_max = 524288', 'kernel.kptr_restrict = 1', 'kernel.yama.ptrace_scope = 2',
            'fs.protected_regular = 2',
        ], 'expected sysctl file to be written');
        $this->assertStringContainsString("/etc/sysctl.d/99-pmss.conf", $this->pmssReadRepoFile('scripts/lib/update/systemPrep/sysctlTuning.php'));
    }

    public function testWritesBbrModulesLoadFile(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-sysctl-bbr-', 0700);
        $target = $dir.'/sysctl.conf';
        $modulesLoad = $dir.'/modules-load.conf';
        $this->pmssTrackEnvOverrides(['PMSS_CONFIG_DIR' => $dir.'/config']);
        $messages = [];
        $this->runBaseline($target, $messages, false, $modulesLoad);

        $this->pmssAssertFileContainsAllStrings($modulesLoad, ['tcp_bbr'], 'expected BBR modules-load file to be written');
    }

    public function testSkipsWhenUpToDate(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-sysctl-skip-', 0700);
        $target = $dir.'/sysctl.conf';
        $this->pmssTrackEnvOverrides(['PMSS_CONFIG_DIR' => $dir.'/config']);

        $messages = [];
        $this->runBaseline($target, $messages, false);
        $first = (string)file_get_contents($target);

        $messages = [];
        $this->runBaseline($target, $messages, false);
        $second = (string)file_get_contents($target);

        $this->assertEquals($first, $second, 'expected sysctl file unchanged');
        $this->assertTrue($this->pmssMessagesContain($messages, 'already present and up to date'), 'expected skip log');
    }

    public function testCreatesTargetDirectory(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-sysctl-dir-', 0700);
        $targetDir = $dir.'/nested';
        $target = $targetDir.'/sysctl.conf';
        $this->pmssTrackEnvOverrides(['PMSS_CONFIG_DIR' => $dir.'/config']);

        $messages = [];
        $this->runBaseline($target, $messages, false);

        $this->assertTrue(is_dir($targetDir), 'expected target directory to exist');
        $this->assertTrue(file_exists($target), 'expected sysctl file to be written');
    }

    public function testReloadSkipLogWhenDisabled(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-sysctl-reload-', 0700);
        $target = $dir.'/sysctl.conf';
        $this->pmssTrackEnvOverrides(['PMSS_CONFIG_DIR' => $dir.'/config']);
        $messages = [];
        $this->runBaseline($target, $messages, false);

        $this->assertTrue($this->pmssMessagesContain($messages, 'sysctl reload disabled'), 'expected reload skip log');
    }

    public function testUpdatesWhenContentDiffers(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-sysctl-update-', 0700);
        $target = $dir.'/sysctl.conf';
        $this->pmssTrackEnvOverrides(['PMSS_CONFIG_DIR' => $dir.'/config']);
        file_put_contents($target, "kernel.kptr_restrict = 0\n");

        $messages = [];
        $this->runBaseline($target, $messages, false);

        $content = (string)file_get_contents($target);
        $this->assertStringContainsString('kernel.kptr_restrict = 1', $content);
        $this->assertTrue($content !== "kernel.kptr_restrict = 0\n", 'expected content updated');
    }

    public function testWarnsWhenSysctlTargetWriteFails(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-sysctl-fail-', 0700);
        $blocked = $dir.'/blocked';
        $target = $blocked.'/sysctl.conf';
        $this->pmssTrackEnvOverrides(['PMSS_CONFIG_DIR' => $dir.'/config']);
        $messages = [];

        file_put_contents($blocked, "not a directory\n");

        $this->runBaseline($target, $messages, false);

        $this->assertFalse(file_exists($target), 'expected sysctl file write to fail');
        $this->assertTrue($this->pmssMessagesContain($messages, 'Unable to write legacy sysctl defaults'), 'expected write failure warning');
        $this->assertFalse($this->pmssMessagesContain($messages, 'Refreshed legacy sysctl defaults'), 'did not expect success log');
        $this->assertFalse($this->pmssMessagesContain($messages, 'sysctl reload disabled'), 'did not expect reload log after failed write');
    }

    public function testVmProfileUsesConservativeMemorySettings(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-sysctl-vm-', 0700);
        $target = $dir.'/sysctl.conf';
        $this->pmssTrackEnvOverrides([
            'PMSS_CONFIG_DIR' => $dir.'/config',
            'PMSS_SYSCTL_IS_VM' => '1',
            'PMSS_SYSCTL_HAS_SWAP' => '1',
            'PMSS_SYSCTL_SWAP_IS_FAST' => '1',
        ]);

        $messages = [];
        $this->runBaseline($target, $messages, false);

        $this->pmssAssertFileContainsAllStrings($target, [
            'vm.swappiness = 10',
            'vm.vfs_cache_pressure = 50',
            'vm.min_free_kbytes = 131072',
            'vm.dirty_ratio = 20',
        ]);
    }

    public function testNoSwapProfileFallsBackToBalancedDefaults(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-sysctl-noswap-', 0700);
        $target = $dir.'/sysctl.conf';
        $this->pmssTrackEnvOverrides([
            'PMSS_CONFIG_DIR' => $dir.'/config',
            'PMSS_SYSCTL_HAS_SWAP' => '0',
            'PMSS_SYSCTL_SWAP_IS_FAST' => '0',
        ]);

        $messages = [];
        $this->runBaseline($target, $messages, false);

        $this->pmssAssertFileContainsAllStrings($target, [
            'vm.swappiness = 60',
            'vm.vfs_cache_pressure = 50',
            'vm.dirty_background_ratio = 5',
        ]);
    }

    public function testSettingsBuildKeepsProfileBranchSnapshot(): void
    {
        $cases = [
            'no_swap' => [['ram_gb' => 64, 'has_swap' => false, 'swap_is_fast' => false, 'is_vm' => false, 'nic_speed_gbps' => 1], ['60', '50', '327680', '20', '16777216']],
            'vm' => [['ram_gb' => 64, 'has_swap' => true, 'swap_is_fast' => true, 'is_vm' => true, 'nic_speed_gbps' => 1], ['10', '50', '131072', '20', '16777216']],
            'fast_swap_10g' => [['ram_gb' => 256, 'has_swap' => true, 'swap_is_fast' => true, 'is_vm' => false, 'nic_speed_gbps' => 10], ['100', '2', '2621440', '40', '67108864']],
            'slow_swap' => [['ram_gb' => 64, 'has_swap' => true, 'swap_is_fast' => false, 'is_vm' => false, 'nic_speed_gbps' => 1], ['10', '50', '327680', '20', '16777216']],
        ];

        foreach ($cases as $label => [$profile, $expected]) {
            $settings = \pmssSysctlSettingsBuild($profile);
            $this->assertSame($expected[0], $settings['vm']['vm.swappiness'], $label.' swappiness changed');
            $this->assertSame($expected[1], $settings['vm']['vm.vfs_cache_pressure'], $label.' cache pressure changed');
            $this->assertSame($expected[2], $settings['vm']['vm.min_free_kbytes'], $label.' min_free_kbytes changed');
            $this->assertSame($expected[3], $settings['vm']['vm.dirty_ratio'], $label.' dirty ratio changed');
            $this->assertSame($expected[4], $settings['net']['net.core.rmem_max'], $label.' network buffer changed');
        }
    }

    public function testRespectsOperatorOwnedOverrideKeys(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-sysctl-overrides-', 0700);
        $target = $dir.'/sysctl.conf';
        $configDir = $dir.'/config';
        $overridePath = $dir.'/90-pmss-overrides.conf';
        $this->pmssTrackEnvOverrides([
            'PMSS_CONFIG_DIR' => $configDir,
            'PMSS_SYSCTL_OVERRIDES_PATH' => $overridePath,
        ]);
        file_put_contents($overridePath, "vm.swappiness = 70\nnet.core.somaxconn = 9000\n");

        $messages = [];
        $this->runBaseline($target, $messages, false);

        $content = (string) file_get_contents($target);
        $this->pmssAssertStringNotContainsString('vm.swappiness =', $content, 'expected override key to be omitted');
        $this->pmssAssertStringNotContainsString('net.core.somaxconn =', $content, 'expected override key to be omitted');

        $summary = $this->pmssReadJsonArrayFile($configDir.'/hardware.json', null, 'expected hardware summary json');
        $this->assertEquals(
            ['vm.swappiness', 'net.core.somaxconn'],
            $summary['sysctl']['overrides_respected'],
            'expected override keys to be reported'
        );
    }

    public function testSysctlConfigAssignmentParsersKeepLegacyCommentHandling(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-sysctl-parser-', 0700);
        $path = $dir.'/sysctl.conf';
        file_put_contents($path, implode("\n", [
            '# operator notes',
            'vm.swappiness = 70 # override comment',
            'net.core.somaxconn = 9000',
            'empty.value =',
            'bad-key = 1',
            '',
        ])."\n");

        $this->assertSame(
            [
                'vm.swappiness',
                'net.core.somaxconn',
                'empty.value',
            ],
            \pmssSysctlOverridesParse($path),
            'override parser should strip inline comments and accept key-only overrides'
        );
        $this->assertSame(
            [
                'vm.swappiness' => '70 # override comment',
                'net.core.somaxconn' => '9000',
            ],
            \pmssSysctlFileParse($path),
            'file parser should preserve inline comments as legacy value text'
        );
    }

    public function testSysctlGroupedSettingsRowsLockChangeOrder(): void
    {
        $grouped = [
            'vm' => ['vm.swappiness' => '10'],
            'ignored' => 'not-array',
            'net' => ['net.core.somaxconn' => 2000],
        ];

        $this->assertSame(
            [
                ['vm.swappiness', '10'],
                ['net.core.somaxconn', '2000'],
            ],
            \pmssSysctlGroupedSettingsRows($grouped)
        );
        $this->assertSame(
            [
                'vm.swappiness: 60 -> 10',
                'net.core.somaxconn: <unset> -> 2000',
            ],
            \pmssSysctlChangesDescribe(['vm.swappiness' => '60'], $grouped)
        );
    }

    public function testWritesHardwareSummaryJson(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-sysctl-summary-', 0700);
        $target = $dir.'/sysctl.conf';
        $configDir = $dir.'/config';
        $this->pmssTrackEnvOverrides([
            'PMSS_CONFIG_DIR' => $configDir,
            'PMSS_TOTAL_MEM_MIB' => '65536',
            'PMSS_SYSCTL_HAS_SWAP' => '1',
            'PMSS_SYSCTL_SWAP_IS_FAST' => '0',
            'PMSS_SYSCTL_NIC_SPEED_MBPS' => '1000',
        ]);

        $messages = [];
        $this->runBaseline($target, $messages, false);

        $summaryPath = $configDir.'/hardware.json';
        $this->assertTrue(file_exists($summaryPath), 'expected hardware summary to be written');
        $summary = $this->pmssReadJsonArrayFile($summaryPath, null, 'expected hardware summary json');
        $this->assertEquals(64, $summary['sysctl']['detection']['ram_gb'], 'expected RAM detection in summary');
        $this->assertFalse($summary['sysctl']['detection']['swap_is_fast'], 'expected slow swap summary');
        $this->assertEquals('10', $summary['sysctl']['applied']['vm.swappiness'], 'expected applied swappiness in summary');
    }

    private function runBaseline(string $target, array &$messages, bool $reload, ?string $modulesLoad = null): void
    {
        $logger = $this->pmssMakeArrayLogger($messages);
        $modulesLoad = $modulesLoad ?? dirname($target).'/modules-load.conf';
        \pmssEnsureLegacySysctlBaseline($logger, $target, $reload, $modulesLoad);
    }

}
