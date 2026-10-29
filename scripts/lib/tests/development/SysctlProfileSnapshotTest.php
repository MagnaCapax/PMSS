<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

final class SysctlProfileSnapshotTest extends TestCase
{
    public function testMemorySettingsKeepBoundaryAndPrecedenceSnapshots(): void
    {
        // Captured before compression; serialization also locks key order and string types.
        $cases = [
            [[], '94fdb4f030e86adea3ff78a70ad37d82f1400a639e44b8c29c98eae4e821ba05'],
            [['ram_gb' => 0, 'has_swap' => true], '950d3fdc050257f0145bae399569dd3bfef4c8e759f883f8d5ddb2c4871ae8ba'],
            [['ram_gb' => 10000, 'has_swap' => true], '8fe0ca406295d602ddda344e2ff97918ba28330e1c33e68f19f7f9b2438abc72'],
            [['ram_gb' => 10000, 'has_swap' => true, 'swap_is_fast' => true], '5be9627ba9712e927104c5ade94ecf7f2324a426b3f02cc4591c4f26ebe0437a'],
            [['has_swap' => false, 'is_vm' => true, 'swap_is_fast' => true], '94fdb4f030e86adea3ff78a70ad37d82f1400a639e44b8c29c98eae4e821ba05'],
            [['has_swap' => true, 'is_vm' => true, 'swap_is_fast' => false], '950d3fdc050257f0145bae399569dd3bfef4c8e759f883f8d5ddb2c4871ae8ba'],
        ];
        foreach ($cases as [$profile, $hash]) {
            $this->assertSame($hash, hash('sha256', serialize(\pmssSysctlMemorySettingsBuild($profile))));
        }
    }

    public function testSettingsBuildKeepsProfileSnapshotHashes(): void
    {
        $procSysRoot = $this->pmssMakeTempDir('pmss-sysctl-profile-proc-', 0700);
        $this->pmssTrackEnvOverrides(['PMSS_SYSCTL_PROC_SYS_PATH' => $procSysRoot]);
        $cases = [
            'no_swap' => [['ram_gb' => 64, 'has_swap' => false, 'swap_is_fast' => false, 'is_vm' => false, 'nic_speed_gbps' => 1], '5535536214adc6cc48f00eaec8ba0e691cf6303d41c3ef66be992af7b26f9dfe'],
            'vm' => [['ram_gb' => 64, 'has_swap' => true, 'swap_is_fast' => true, 'is_vm' => true, 'nic_speed_gbps' => 1], '9ea1b73ea0ef5123279a3e91812a5063d44ee52f9390d02f79cbead5980738cf'],
            'fast_swap_10g_conntrack' => [['ram_gb' => 256, 'has_swap' => true, 'swap_is_fast' => true, 'is_vm' => false, 'nic_speed_gbps' => 10, 'has_conntrack' => true], 'c3838b52e37d193d2e6740915c29e471d47ca89b4ed7da99ca479f6fad86a051'],
            'slow_swap' => [['ram_gb' => 64, 'has_swap' => true, 'swap_is_fast' => false, 'is_vm' => false, 'nic_speed_gbps' => 1], '9ea1b73ea0ef5123279a3e91812a5063d44ee52f9390d02f79cbead5980738cf'],
        ];

        foreach ($cases as $label => [$profile, $expectedHash]) {
            $rendered = \pmssSysctlConfigRender(\pmssSysctlSettingsBuild($profile));
            $this->assertSame($expectedHash, hash('sha256', $rendered), $label.' rendered sysctl profile changed');
        }
    }

    public function testConntrackBuilderKeepsLegacyTimeWaitKeySelection(): void
    {
        $procSysRoot = $this->pmssMakeTempDir('pmss-sysctl-profile-legacy-proc-', 0700);
        $legacyDir = $this->pmssEnsureDir($procSysRoot.'/net/ipv4/netfilter');
        file_put_contents($legacyDir.'/ip_conntrack_tcp_timeout_time_wait', '');
        $this->pmssTrackEnvOverrides(['PMSS_SYSCTL_PROC_SYS_PATH' => $procSysRoot]);

        $settings = \pmssSysctlConntrackSettingsBuild(['has_conntrack' => true]);

        $this->assertSame('15', $settings['net.ipv4.netfilter.ip_conntrack_tcp_timeout_time_wait']);
        $this->assertFalse(isset($settings['net.netfilter.nf_conntrack_tcp_timeout_time_wait']));
    }
}
