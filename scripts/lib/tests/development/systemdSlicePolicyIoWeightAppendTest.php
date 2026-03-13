<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class SystemdSlicePolicyIoWeightAppendTest extends TestCase
{
    private function tempDir(string $prefix): string
    {
        $d = sys_get_temp_dir().'/pmss-cg-'.bin2hex(random_bytes(4)).'-'.$prefix;
        @mkdir($d, 0700, true);
        return $d;
    }

    public function testIODeviceWeightAppendedWhenConfigured(): void
    {
        $cfgDir = $this->tempDir('cfg');
        $drop   = $this->tempDir('drop');
        $tpl = "[Slice]\nTasksMax=%%USER_CGROUP_TASKS_MAX%%\n";
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v2.conf', $tpl);
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v1.conf', 'ignored');
        $policy = <<<'PHP'
<?php return [
  'tasksMax'=>512,
  'mounts' => [ '/' => ['ioWeight'=>90] ],
];
PHP;
        file_put_contents($cfgDir.'/cgroup.policy.php', $policy);
        putenv('PMSS_CGROUP_MODE=v2');
        putenv('PMSS_CONFIG_DIR='.$cfgDir);
        putenv('PMSS_SYSTEMD_USER_SLICE_DIR='.$drop);
        putenv('PMSS_TOTAL_MEM_MIB=2048');
        \pmssEnsureSystemdSlices('logmsg');
        $out = (string)file_get_contents($drop.'/15-pmss.conf');
        $this->assertTrue(strpos($out, 'IODeviceWeight=') !== false, 'IODeviceWeight not appended');
    }

    public function testIODeviceWeightSkippedOnV1(): void
    {
        $cfgDir = $this->tempDir('cfg');
        $drop   = $this->tempDir('drop');
        $tpl = "[Slice]\nTasksMax=%%USER_CGROUP_TASKS_MAX%%\n";
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v1.conf', $tpl);
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v2.conf', 'ignored');
        $policy = <<<'PHP'
<?php return [
  'tasksMax'=>512,
  'mounts' => [ '/' => ['ioWeight'=>90] ],
];
PHP;
        file_put_contents($cfgDir.'/cgroup.policy.php', $policy);
        putenv('PMSS_CGROUP_MODE=v1');
        putenv('PMSS_CONFIG_DIR='.$cfgDir);
        putenv('PMSS_SYSTEMD_USER_SLICE_DIR='.$drop);
        putenv('PMSS_TOTAL_MEM_MIB=2048');
        \pmssEnsureSystemdSlices('logmsg');
        $out = (string)file_get_contents($drop.'/15-pmss.conf');
        $this->assertTrue(strpos($out, 'IODeviceWeight=') === false, 'IODeviceWeight should be skipped on v1');
    }

    public function testIOPSLimitsAppendedWhenConfigured(): void
    {
        $cfgDir = $this->tempDir('cfg');
        $drop   = $this->tempDir('drop');
        $tpl = "[Slice]\nTasksMax=%%USER_CGROUP_TASKS_MAX%%\n";
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v2.conf', $tpl);
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v1.conf', 'ignored');
        $policy = <<<'PHP'
<?php return [
  'tasksMax'=>512,
  'mounts' => [ '/' => ['readIops'=>77, 'writeIops'=>88] ],
];
PHP;
        file_put_contents($cfgDir.'/cgroup.policy.php', $policy);
        putenv('PMSS_CGROUP_MODE=v2');
        putenv('PMSS_CONFIG_DIR='.$cfgDir);
        putenv('PMSS_SYSTEMD_USER_SLICE_DIR='.$drop);
        putenv('PMSS_TOTAL_MEM_MIB=2048');
        \pmssEnsureSystemdSlices('logmsg');
        $out = (string)file_get_contents($drop.'/15-pmss.conf');
        $this->assertTrue(strpos($out, 'IOReadIOPSMax=') !== false, 'IOReadIOPSMax not appended');
        $this->assertTrue(strpos($out, 'IOWriteIOPSMax=') !== false, 'IOWriteIOPSMax not appended');
    }

    public function testBandwidthLimitsAppendedWhenConfigured(): void
    {
        $cfgDir = $this->tempDir('cfg');
        $drop   = $this->tempDir('drop');
        $tpl = "[Slice]\nTasksMax=%%USER_CGROUP_TASKS_MAX%%\n";
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v2.conf', $tpl);
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v1.conf', 'ignored');
        $policy = <<<'PHP'
<?php return [
  'tasksMax'=>512,
  'mounts' => [ '/' => ['readBw'=>'100M', 'writeBw'=>'120M'] ],
];
PHP;
        file_put_contents($cfgDir.'/cgroup.policy.php', $policy);
        putenv('PMSS_CGROUP_MODE=v2');
        putenv('PMSS_CONFIG_DIR='.$cfgDir);
        putenv('PMSS_SYSTEMD_USER_SLICE_DIR='.$drop);
        putenv('PMSS_TOTAL_MEM_MIB=2048');
        \pmssEnsureSystemdSlices('logmsg');
        $out = (string)file_get_contents($drop.'/15-pmss.conf');
        $this->assertTrue(strpos($out, 'IOReadBandwidthMax=') !== false, 'IOReadBandwidthMax not appended');
        $this->assertTrue(strpos($out, 'IOWriteBandwidthMax=') !== false, 'IOWriteBandwidthMax not appended');
    }
}
