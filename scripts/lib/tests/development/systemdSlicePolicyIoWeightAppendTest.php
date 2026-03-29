<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class SystemdSlicePolicyIoWeightAppendTest extends TestCase
{
    public function testIODeviceWeightAppendedWhenConfigured(): void
    {
        $tpl = "[Slice]\nTasksMax=%%USER_CGROUP_TASKS_MAX%%\n";
        $policy = <<<'PHP'
<?php return [
  'tasksMax'=>512,
  'mounts' => [ '/' => ['ioWeight'=>90] ],
];
PHP;

        $out = $this->pmssSystemdSliceDropinRender($this->pmssSystemdSliceFixturePrepare([
            'v2Template' => $tpl,
            'policy' => $policy,
            'totalMemMiB' => 2048,
        ]));
        $this->assertTrue(strpos($out, 'IODeviceWeight=') !== false, 'IODeviceWeight not appended');
    }

    public function testIODeviceWeightSkippedOnV1(): void
    {
        $tpl = "[Slice]\nTasksMax=%%USER_CGROUP_TASKS_MAX%%\n";
        $policy = <<<'PHP'
<?php return [
  'tasksMax'=>512,
  'mounts' => [ '/' => ['ioWeight'=>90] ],
];
PHP;

        $out = $this->pmssSystemdSliceDropinRender($this->pmssSystemdSliceFixturePrepare([
            'mode' => 'v1',
            'v1Template' => $tpl,
            'policy' => $policy,
            'totalMemMiB' => 2048,
        ]));
        $this->assertTrue(strpos($out, 'IODeviceWeight=') === false, 'IODeviceWeight should be skipped on v1');
    }

    public function testIOPSLimitsAppendedWhenConfigured(): void
    {
        $tpl = "[Slice]\nTasksMax=%%USER_CGROUP_TASKS_MAX%%\n";
        $policy = <<<'PHP'
<?php return [
  'tasksMax'=>512,
  'mounts' => [ '/' => ['readIops'=>77, 'writeIops'=>88] ],
];
PHP;

        $out = $this->pmssSystemdSliceDropinRender($this->pmssSystemdSliceFixturePrepare([
            'v2Template' => $tpl,
            'policy' => $policy,
            'totalMemMiB' => 2048,
        ]));
        $this->assertTrue(strpos($out, 'IOReadIOPSMax=') !== false, 'IOReadIOPSMax not appended');
        $this->assertTrue(strpos($out, 'IOWriteIOPSMax=') !== false, 'IOWriteIOPSMax not appended');
    }

    public function testBandwidthLimitsAppendedWhenConfigured(): void
    {
        $tpl = "[Slice]\nTasksMax=%%USER_CGROUP_TASKS_MAX%%\n";
        $policy = <<<'PHP'
<?php return [
  'tasksMax'=>512,
  'mounts' => [ '/' => ['readBw'=>'100M', 'writeBw'=>'120M'] ],
];
PHP;

        $out = $this->pmssSystemdSliceDropinRender($this->pmssSystemdSliceFixturePrepare([
            'v2Template' => $tpl,
            'policy' => $policy,
            'totalMemMiB' => 2048,
        ]));
        $this->assertTrue(strpos($out, 'IOReadBandwidthMax=') !== false, 'IOReadBandwidthMax not appended');
        $this->assertTrue(strpos($out, 'IOWriteBandwidthMax=') !== false, 'IOWriteBandwidthMax not appended');
    }
}
