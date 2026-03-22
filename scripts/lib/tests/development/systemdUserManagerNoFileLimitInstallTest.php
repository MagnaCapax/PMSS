<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class SystemdUserManagerNoFileLimitInstallTest extends TestCase
{
    private function withDropinDir(string $dir, callable $callback): void
    {
        $previous = getenv('PMSS_SYSTEMD_USER_AT_SERVICE_DIR');
        putenv('PMSS_SYSTEMD_USER_AT_SERVICE_DIR='.$dir);
        try {
            $callback();
        } finally {
            if ($previous === false) {
                putenv('PMSS_SYSTEMD_USER_AT_SERVICE_DIR');
            } else {
                putenv('PMSS_SYSTEMD_USER_AT_SERVICE_DIR='.$previous);
            }
        }
    }

    public function testInstallsConfiguredSoftHardLimits(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-systemd-user-at-install-');
        $this->withDropinDir($dir, function (): void {
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
    }

    public function testClampsHardLimitUpToSoftLimit(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-systemd-user-at-clamp-');
        $this->withDropinDir($dir, function (): void {
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
        $this->withDropinDir($dir, function (): void {
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

        $previousConfigDir = getenv('PMSS_CONFIG_DIR');
        $previousSliceDir = getenv('PMSS_SYSTEMD_USER_SLICE_DIR');
        $previousMode = getenv('PMSS_CGROUP_MODE');
        $previousMem = getenv('PMSS_TOTAL_MEM_MIB');
        $previousCpu = getenv('PMSS_TOTAL_CPU_THREADS');

        $this->withDropinDir($dir, function () use ($cfgDir, $sliceDir, $previousConfigDir, $previousSliceDir, $previousMode, $previousMem, $previousCpu): void {
            putenv('PMSS_CONFIG_DIR='.$cfgDir);
            putenv('PMSS_SYSTEMD_USER_SLICE_DIR='.$sliceDir);
            putenv('PMSS_CGROUP_MODE=v2');
            putenv('PMSS_TOTAL_MEM_MIB=4096');
            putenv('PMSS_TOTAL_CPU_THREADS=4');

            try {
                \pmssEnsureSystemdSlices(function (): void {
                });
            } finally {
                if ($previousConfigDir === false) {
                    putenv('PMSS_CONFIG_DIR');
                } else {
                    putenv('PMSS_CONFIG_DIR='.$previousConfigDir);
                }
                if ($previousSliceDir === false) {
                    putenv('PMSS_SYSTEMD_USER_SLICE_DIR');
                } else {
                    putenv('PMSS_SYSTEMD_USER_SLICE_DIR='.$previousSliceDir);
                }
                if ($previousMode === false) {
                    putenv('PMSS_CGROUP_MODE');
                } else {
                    putenv('PMSS_CGROUP_MODE='.$previousMode);
                }
                if ($previousMem === false) {
                    putenv('PMSS_TOTAL_MEM_MIB');
                } else {
                    putenv('PMSS_TOTAL_MEM_MIB='.$previousMem);
                }
                if ($previousCpu === false) {
                    putenv('PMSS_TOTAL_CPU_THREADS');
                } else {
                    putenv('PMSS_TOTAL_CPU_THREADS='.$previousCpu);
                }
            }
        });

        $target = $dir.'/30-pmss-log-namespace.conf';
        $this->assertTrue(is_file($target), 'Expected user@.service log namespace drop-in to be created');
        $body = (string)file_get_contents($target);
        $this->assertStringContainsString('LogNamespace=user-%i', $body);
    }
}
