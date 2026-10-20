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
            [[], '9a9cf3d8ec6c62d0ddc54d049630476a9059f40efb93370462ce30099e4e5d9b'],
            [['ram_gb' => 0, 'has_swap' => true], '866e770540212567d4448541772eb034eae7da6289037007b5958feb9afd8c92'],
            [['ram_gb' => 10000, 'has_swap' => true], '8fe0ca406295d602ddda344e2ff97918ba28330e1c33e68f19f7f9b2438abc72'],
            [['ram_gb' => 10000, 'has_swap' => true, 'swap_is_fast' => true], '5be9627ba9712e927104c5ade94ecf7f2324a426b3f02cc4591c4f26ebe0437a'],
            [['has_swap' => false, 'is_vm' => true, 'swap_is_fast' => true], '9a9cf3d8ec6c62d0ddc54d049630476a9059f40efb93370462ce30099e4e5d9b'],
            [['has_swap' => true, 'is_vm' => true, 'swap_is_fast' => false], '866e770540212567d4448541772eb034eae7da6289037007b5958feb9afd8c92'],
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
            'no_swap' => [['ram_gb' => 64, 'has_swap' => false, 'swap_is_fast' => false, 'is_vm' => false, 'nic_speed_gbps' => 1], '4c187f796cc18d2db8fe56955a6fc217dd119a8ccb33d878db0f029c37edffaa'],
            'vm' => [['ram_gb' => 64, 'has_swap' => true, 'swap_is_fast' => true, 'is_vm' => true, 'nic_speed_gbps' => 1], 'c5cc1ecee1ff795c8881b03ffabd0acf20f3d85dfb51a8a3f89da4c9956afce8'],
            'fast_swap_10g_conntrack' => [['ram_gb' => 256, 'has_swap' => true, 'swap_is_fast' => true, 'is_vm' => false, 'nic_speed_gbps' => 10, 'has_conntrack' => true], 'c3838b52e37d193d2e6740915c29e471d47ca89b4ed7da99ca479f6fad86a051'],
            'slow_swap' => [['ram_gb' => 64, 'has_swap' => true, 'swap_is_fast' => false, 'is_vm' => false, 'nic_speed_gbps' => 1], '6165674bef1934011c6912bf0d64794add2f242ecf9d6eac67eeb86a2666a1a8'],
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
