<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class SystemdSlicePolicyIoWeightAppendTest extends TestCase
{
    public function testIODeviceWeightAppendedWhenConfigured(): void
    {
        $out = $this->pmssSystemdSliceRender([
            'v2Template' => $this->pmssSystemdSliceTasksTemplate(),
            'policy' => $this->pmssSystemdSlicePolicySource([
                'tasksMax' => 512,
                'mounts' => ['/'=> ['ioWeight' => 90]],
            ]),
            'totalMemMiB' => 2048,
        ]);
        $this->assertTrue(strpos($out, 'IODeviceWeight=') !== false, 'IODeviceWeight not appended');
    }

    public function testIODeviceWeightSkippedOnV1(): void
    {
        $out = $this->pmssSystemdSliceRender([
            'mode' => 'v1',
            'v1Template' => $this->pmssSystemdSliceTasksTemplate(),
            'policy' => $this->pmssSystemdSlicePolicySource([
                'tasksMax' => 512,
                'mounts' => ['/'=> ['ioWeight' => 90]],
            ]),
            'totalMemMiB' => 2048,
        ]);
        $this->assertTrue(strpos($out, 'IODeviceWeight=') === false, 'IODeviceWeight should be skipped on v1');
    }

    public function testIOPSLimitsAppendedWhenConfigured(): void
    {
        $out = $this->pmssSystemdSliceRender([
            'v2Template' => $this->pmssSystemdSliceTasksTemplate(),
            'policy' => $this->pmssSystemdSlicePolicySource([
                'tasksMax' => 512,
                'mounts' => ['/'=> ['readIops' => 77, 'writeIops' => 88]],
            ]),
            'totalMemMiB' => 2048,
        ]);
        $this->assertTrue(strpos($out, 'IOReadIOPSMax=') !== false, 'IOReadIOPSMax not appended');
        $this->assertTrue(strpos($out, 'IOWriteIOPSMax=') !== false, 'IOWriteIOPSMax not appended');
    }

    public function testBandwidthLimitsAppendedWhenConfigured(): void
    {
        $out = $this->pmssSystemdSliceRender([
            'v2Template' => $this->pmssSystemdSliceTasksTemplate(),
            'policy' => $this->pmssSystemdSlicePolicySource([
                'tasksMax' => 512,
                'mounts' => ['/'=> ['readBw' => '100M', 'writeBw' => '120M']],
            ]),
            'totalMemMiB' => 2048,
        ]);
        $this->assertTrue(strpos($out, 'IOReadBandwidthMax=') !== false, 'IOReadBandwidthMax not appended');
        $this->assertTrue(strpos($out, 'IOWriteBandwidthMax=') !== false, 'IOWriteBandwidthMax not appended');
    }
}
