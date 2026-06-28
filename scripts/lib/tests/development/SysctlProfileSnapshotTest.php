<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

final class SysctlProfileSnapshotTest extends TestCase
{
    public function testSettingsBuildKeepsProfileSnapshotHashes(): void
    {
        $procSysRoot = $this->pmssMakeTempDir('pmss-sysctl-profile-proc-', 0700);
        $this->pmssTrackEnvOverrides(['PMSS_SYSCTL_PROC_SYS_PATH' => $procSysRoot]);
        $cases = [
            'no_swap' => [['ram_gb' => 64, 'has_swap' => false, 'swap_is_fast' => false, 'is_vm' => false, 'nic_speed_gbps' => 1], 'f17ce9f43100072f8679109d605b3cc1c01914da07c2fdb62a6af83502c2699d'],
            'vm' => [['ram_gb' => 64, 'has_swap' => true, 'swap_is_fast' => true, 'is_vm' => true, 'nic_speed_gbps' => 1], '0dcb0cf026bf5548df788c2ff2afa42b4625a5c35020919bb2dcb8ce42593c95'],
            'fast_swap_10g_conntrack' => [['ram_gb' => 256, 'has_swap' => true, 'swap_is_fast' => true, 'is_vm' => false, 'nic_speed_gbps' => 10, 'has_conntrack' => true], '9f472ab7b28e0bafb66f0e0264698e6521d1cd6a54721e79fa14fabee5079d49'],
            'slow_swap' => [['ram_gb' => 64, 'has_swap' => true, 'swap_is_fast' => false, 'is_vm' => false, 'nic_speed_gbps' => 1], '8bbb1aaa16004a899de6a90f55657493e47496151b6ffe36b1a0d5d9934efc92'],
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
