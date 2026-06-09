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
            'policy' => [
                'tasksMax' => 512,
                'mounts' => ['/'=> ['ioWeight' => 90]],
            ],
            'totalMemMiB' => 2048,
        ]);
        $this->assertTrue(strpos($out, 'IODeviceWeight=') !== false, 'IODeviceWeight not appended');
    }

    public function testIODeviceWeightSkippedOnV1(): void
    {
        $out = $this->pmssSystemdSliceRender([
            'mode' => 'v1',
            'v1Template' => $this->pmssSystemdSliceTasksTemplate(),
            'policy' => [
                'tasksMax' => 512,
                'mounts' => ['/'=> ['ioWeight' => 90]],
            ],
            'totalMemMiB' => 2048,
        ]);
        $this->assertTrue(strpos($out, 'IODeviceWeight=') === false, 'IODeviceWeight should be skipped on v1');
    }

    public function testIOPSLimitsAppendedWhenConfigured(): void
    {
        $out = $this->pmssSystemdSliceRender([
            'v2Template' => $this->pmssSystemdSliceTasksTemplate(),
            'policy' => [
                'tasksMax' => 512,
                'mounts' => ['/'=> ['readIops' => 77, 'writeIops' => 88]],
            ],
            'totalMemMiB' => 2048,
        ]);
        $this->assertTrue(strpos($out, 'IOReadIOPSMax=') !== false, 'IOReadIOPSMax not appended');
        $this->assertTrue(strpos($out, 'IOWriteIOPSMax=') !== false, 'IOWriteIOPSMax not appended');
    }

    public function testBandwidthLimitsAppendedWhenConfigured(): void
    {
        $findmntPath = $this->pmssMakeExecutableStub('findmnt', "#!/bin/sh\nprintf '%s\\n' '/dev/md0'\n", 'pmss-findmnt-bandwidth-');
        $out = $this->pmssSystemdSliceRender([
            'v2Template' => $this->pmssSystemdSliceTasksTemplate(),
            'policy' => [
                'tasksMax' => 512,
                'mounts' => ['/'=> ['readBw' => '100M', 'writeBw' => '120M']],
            ],
            'env' => $this->pmssPathPrefixedEnvironment($findmntPath),
            'totalMemMiB' => 2048,
        ]);
        $this->assertStringContainsString(
            "\nIOReadBandwidthMax=/dev/md0 100M\n"
            ."IOWriteBandwidthMax=/dev/md0 120M\n",
            $out
        );
    }

    public function testIODeviceLatencyUsesHomeBackingDeviceOnV2(): void
    {
        $findmntPath = $this->pmssMakeExecutableStub(
            'findmnt',
            "#!/bin/sh\nif [ \"$3\" = \"/home\" ]; then\n  printf '%s\\n' '/dev/md0'\nfi\n",
            'pmss-findmnt-latency-'
        );
        $out = $this->pmssSystemdSliceRender([
            'v2Template' => $this->pmssSystemdSliceTasksTemplate(['%%USER_CGROUP_IO_DEVICE_LATENCY%%']),
            'policy' => [
                'ioLatencyMs' => 50,
            ],
            'env' => $this->pmssPathPrefixedEnvironment($findmntPath),
            'totalMemMiB' => 2048,
        ]);
        $this->assertStringContainsString('IODeviceLatencyTargetSec=/dev/md0 50ms', $out);
    }

    public function testIODeviceLatencySkipsUnsafeHomeBackingDevice(): void
    {
        $findmntPath = $this->pmssMakeExecutableStub(
            'findmnt',
            "#!/bin/sh\nif [ \"$3\" = \"/home\" ]; then\n  printf '%s\\n%s\\n' '/dev/md0' 'TasksMax=infinity'\nfi\n",
            'pmss-findmnt-latency-unsafe-'
        );
        $out = $this->pmssSystemdSliceRender([
            'v2Template' => $this->pmssSystemdSliceTasksTemplate(['%%USER_CGROUP_IO_DEVICE_LATENCY%%']),
            'policy' => [
                'ioLatencyMs' => 50,
            ],
            'env' => $this->pmssPathPrefixedEnvironment($findmntPath),
            'totalMemMiB' => 2048,
        ]);
        $this->assertStringNotContainsString('IODeviceLatencyTargetSec=', $out);
        $this->assertStringNotContainsString('TasksMax=infinity', $out);
    }

    public function testMountIoSkipsUnsafeBackingDevice(): void
    {
        $findmntPath = $this->pmssMakeExecutableStub(
            'findmnt',
            "#!/bin/sh\nprintf '%s\\n' '/dev/bad target'\n",
            'pmss-findmnt-mount-unsafe-'
        );
        $out = $this->pmssSystemdSliceRender([
            'v2Template' => $this->pmssSystemdSliceTasksTemplate(),
            'policy' => [
                'tasksMax' => 512,
                'mounts' => ['/'=> ['readBw' => '100M']],
            ],
            'env' => $this->pmssPathPrefixedEnvironment($findmntPath),
            'totalMemMiB' => 2048,
        ]);
        $this->assertStringNotContainsString('IOReadBandwidthMax=', $out);
    }

    public function testMountIoSkipsUnsafePolicyValue(): void
    {
        $findmntPath = $this->pmssMakeExecutableStub(
            'findmnt',
            "#!/bin/sh\nprintf '%s\\n' '/dev/md0'\n",
            'pmss-findmnt-policy-unsafe-'
        );
        $out = $this->pmssSystemdSliceRender([
            'v2Template' => $this->pmssSystemdSliceTasksTemplate(),
            'policy' => [
                'tasksMax' => 512,
                'mounts' => [
                    '/' => [
                        'readBw' => "100M\nTasksMax=infinity",
                        'writeBw' => '120M',
                    ],
                ],
            ],
            'env' => $this->pmssPathPrefixedEnvironment($findmntPath),
            'totalMemMiB' => 2048,
        ]);
        $this->assertStringNotContainsString('IOReadBandwidthMax=', $out);
        $this->assertStringNotContainsString('TasksMax=infinity', $out);
        $this->assertStringContainsString('IOWriteBandwidthMax=/dev/md0 120M', $out);
    }
}
