<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class SystemdUserManagerNoFileLimitInstallTest extends TestCase
{
    public function testInstallsConfiguredSoftHardLimits(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-systemd-user-at-install-');
        $this->pmssWithEnv(['PMSS_SYSTEMD_USER_AT_SERVICE_DIR' => $dir], function (): void {
            \pmssSystemdUserManagerNoFileLimitInstall([
                'limitNoFileSoft' => 8192,
                'limitNoFileHard' => 16384,
            ], function (): void {
            });
        });

        $target = $dir.'/20-pmss-limits.conf';
        $this->assertTrue(is_file($target), 'Expected user@.service drop-in to be created');
        $body = (string)file_get_contents($target);
        $this->assertStringContainsString('LimitNOFILE=8192:16384', $body);
        $this->assertSame(0644, fileperms($target) & 0777);
        $this->assertTrue(!file_exists($target.'.tmp'), 'Temporary drop-in file should not remain after install');
    }

    public function testClampsHardLimitUpToSoftLimit(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-systemd-user-at-clamp-');
        $this->pmssWithEnv(['PMSS_SYSTEMD_USER_AT_SERVICE_DIR' => $dir], function (): void {
            \pmssSystemdUserManagerNoFileLimitInstall([
                'limitNoFileSoft' => 4096,
                'limitNoFileHard' => 1024,
            ], function (): void {
            });
        });

        $target = $dir.'/20-pmss-limits.conf';
        $this->assertTrue(is_file($target), 'Expected user@.service drop-in to be created');
        $body = (string)file_get_contents($target);
        $this->assertStringContainsString('LimitNOFILE=4096:4096', $body);
    }

    public function testSkipsWhenPolicyDoesNotProvideNoFileValues(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-systemd-user-at-skip-');
        $this->pmssWithEnv(['PMSS_SYSTEMD_USER_AT_SERVICE_DIR' => $dir], function (): void {
            \pmssSystemdUserManagerNoFileLimitInstall([
                'cpuWeight' => 100,
            ], function (): void {
            });
        });

        $target = $dir.'/20-pmss-limits.conf';
        $this->assertTrue(!is_file($target), 'Unexpected user@.service drop-in without LimitNOFILE policy');
    }

    public function testInstallsLogNamespaceDropin(): void
    {
        $cfgDir = $this->pmssMakeTempDir('pmss-systemd-user-at-cfg-');
        $sliceDir = $this->pmssMakeTempDir('pmss-systemd-user-at-slice-');
        $dir = $this->pmssMakeTempDir('pmss-systemd-user-at-log-namespace-');
        file_put_contents(
            $cfgDir.'/template.cgroup.user-slice.v2.conf',
            "[Slice]\nCPUWeight=%%USER_CGROUP_CPU_WEIGHT%%\nIOWeight=%%USER_CGROUP_IO_WEIGHT%%\nTasksMax=%%USER_CGROUP_TASKS_MAX%%\nMemoryHigh=%%USER_CGROUP_MEMORY_HIGH%%M\nMemoryMax=%%USER_CGROUP_MEMORY_MAX%%M\nCPUQuota=%%USER_CGROUP_CPU_QUOTA%%\n"
        );

        $this->pmssWithEnv([
            'PMSS_SYSTEMD_USER_AT_SERVICE_DIR' => $dir,
            'PMSS_CONFIG_DIR' => $cfgDir,
            'PMSS_SYSTEMD_USER_SLICE_DIR' => $sliceDir,
            'PMSS_CGROUP_MODE' => 'v2',
            'PMSS_TOTAL_MEM_MIB' => '4096',
            'PMSS_TOTAL_CPU_THREADS' => '4',
        ], function (): void {
            \pmssEnsureSystemdSlices(function (): void {
            });
        });

        $target = $dir.'/30-pmss-log-namespace.conf';
        $this->assertTrue(is_file($target), 'Expected user@.service log namespace drop-in to be created');
        $body = (string)file_get_contents($target);
        $this->assertStringContainsString('LogNamespace=user-%i', $body);
        $this->assertSame(0644, fileperms($target) & 0777);
        $this->assertTrue(!file_exists($target.'.tmp'), 'Temporary log namespace drop-in should not remain after install');
    }
}
