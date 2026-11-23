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
            'no_swap' => [['ram_gb' => 64, 'has_swap' => false, 'swap_is_fast' => false, 'is_vm' => false, 'nic_speed_gbps' => 1], '91b5e9975ec4a2e66657cfa80320145e6f38a6cde0f88d03f94ea9fa146c4364'],
            'vm' => [['ram_gb' => 64, 'has_swap' => true, 'swap_is_fast' => true, 'is_vm' => true, 'nic_speed_gbps' => 1], 'b21a5a4f66dae6e852b7fb1258d4ddc191603e5c15362a2802d6503e43e9452f'],
            'fast_swap_10g_conntrack' => [['ram_gb' => 256, 'has_swap' => true, 'swap_is_fast' => true, 'is_vm' => false, 'nic_speed_gbps' => 10, 'has_conntrack' => true], '27d99caeb70859cbba1a1f49627ad99c3e6b29c839532f18d8234c825cb7ac9d'],
            'slow_swap' => [['ram_gb' => 64, 'has_swap' => true, 'swap_is_fast' => false, 'is_vm' => false, 'nic_speed_gbps' => 1], 'b21a5a4f66dae6e852b7fb1258d4ddc191603e5c15362a2802d6503e43e9452f'],
        ];

        foreach ($cases as $label => [$profile, $expectedHash]) {
            $rendered = \pmssSysctlConfigRender(\pmssSysctlSettingsBuild($profile));
            $this->assertSame($expectedHash, hash('sha256', $rendered), $label.' rendered sysctl profile changed, got '.hash('sha256', $rendered));
        }
    }

    public function testConntrackBuilderKeepsLegacyTimeWaitKeySelection(): void
    {
        $procSysRoot = $this->pmssMakeTempDir('pmss-sysctl-profile-legacy-proc-', 0700);
        $this->pmssWriteFile($procSysRoot.'/net/ipv4/netfilter/ip_conntrack_tcp_timeout_time_wait', '');
        $this->pmssTrackEnvOverrides(['PMSS_SYSCTL_PROC_SYS_PATH' => $procSysRoot]);

        $settings = \pmssSysctlConntrackSettingsBuild(['has_conntrack' => true]);

        $this->assertSame('15', $settings['net.ipv4.netfilter.ip_conntrack_tcp_timeout_time_wait']);
        $this->assertFalse(isset($settings['net.netfilter.nf_conntrack_tcp_timeout_time_wait']));
    }

    public function testSecurityBuilderDisablesMptcpOnlyWhenKernelHasIt(): void
    {
        $procSysRoot = $this->pmssMakeTempDir('pmss-sysctl-profile-mptcp-proc-', 0700);
        $this->pmssTrackEnvOverrides(['PMSS_SYSCTL_PROC_SYS_PATH' => $procSysRoot]);
        $this->assertFalse(isset(\pmssSysctlSecuritySettingsBuild()['net.mptcp.enabled']));

        $this->pmssWriteFile($procSysRoot.'/net/mptcp/enabled', "1\n");

        $this->assertSame('0', \pmssSysctlSecuritySettingsBuild()['net.mptcp.enabled']);
    }

    public function testSecurityBuilderAlwaysWritesSkippableTiocstiKey(): void
    {
        $procSysRoot = $this->pmssMakeTempDir('pmss-sysctl-profile-tiocsti-proc-', 0700);
        $this->pmssTrackEnvOverrides(['PMSS_SYSCTL_PROC_SYS_PATH' => $procSysRoot]);
        // No dev/tty/legacy_tiocsti under the fake /proc/sys (a 6.1 host): the line is still written, dash-prefixed.
        $this->assertSame('0', \pmssSysctlSecuritySettingsBuild()['-dev.tty.legacy_tiocsti']);
        $this->assertFalse(isset(\pmssSysctlSecuritySettingsBuild()['dev.tty.legacy_tiocsti']));
    }

    public function testDashKeyRoundTripsAndOverridesMatchEitherForm(): void
    {
        $grouped = ['security' => ['-dev.tty.legacy_tiocsti' => '0', 'kernel.kptr_restrict' => '1']];
        $dir = $this->pmssMakeTempDir('pmss-sysctl-dash-', 0700);
        $this->pmssWriteFile($dir.'/99-pmss.conf', \pmssSysctlConfigRender($grouped));
        $this->assertSame([], \pmssSysctlChangesDescribe(\pmssSysctlFileParse($dir.'/99-pmss.conf'), $grouped));
        foreach (['dev.tty.legacy_tiocsti = 1', '-dev.tty.legacy_tiocsti = 1'] as $line) {
            $this->pmssWriteFile($dir.'/90-pmss-overrides.conf', $line."\n");
            $filtered = \pmssSysctlSettingsFilterOverrides($grouped, \pmssSysctlOverridesParse($dir.'/90-pmss-overrides.conf'));
            $this->assertSame(['security' => ['kernel.kptr_restrict' => '1']], $filtered, $line);
        }
    }
}
