<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/UserConfigCgroupCliTrait.php';

class PolicyOverridePrecedenceTest extends TestCase
{
    use UserConfigCgroupCliTrait;

    public function testDefaultsApplyWhenExplicitMissing(): void
    {
        foreach ([
            [
                ['cpuWeight' => 123, 'ioWeight' => 321, 'tasksMax' => 777],
                ['root', '--apply', '--dry-run', '--defaults'],
                ['CPUWeight=123', 'IOWeight=321', 'TasksMax=777'],
            ],
            [
                ['cpuWeight' => 111],
                ['root', '--apply', '--dry-run', '--defaults', '--cpu-weight=999'],
                ['CPUWeight=999'],
            ],
        ] as [$policy, $argv, $expected]) {
            $this->assertStringContainsAllStrings($expected, $this->pmssRunUserConfigCgroupCliWithPolicy($policy, $argv));
        }
    }

    public function testDefaultsExpandPolicyMountIoPairs(): void
    {
        $out = $this->pmssRunUserConfigCgroupCliWithPolicy(
            ['mounts' => ['/home' => ['ioWeight' => 320, 'readBw' => '25M', 'writeBw' => '10M', 'readIops' => 150, 'writeIops' => 90]]],
            ['root', '--apply', '--dry-run', '--defaults'],
            ['PMSS_HOME_DEVICE' => '/dev/testhome']
        );
        $this->assertStringContainsAllStrings(['IODeviceWeight=/dev/testhome 320', 'IOReadBandwidthMax=/dev/testhome 25M', 'IOWriteBandwidthMax=/dev/testhome 10M', 'IOReadIOPSMax=/dev/testhome 150', 'IOWriteIOPSMax=/dev/testhome 90'], $out);
    }

    public function testExplicitIoFlagsOverridePolicyMountIoPairs(): void
    {
        $out = $this->pmssRunUserConfigCgroupCliWithPolicy(
            ['mounts' => ['/home' => ['readBw' => '25M']]],
            ['root', '--apply', '--dry-run', '--defaults', '--io-read-bw=/dev/manual:9M'],
            ['PMSS_HOME_DEVICE' => '/dev/testhome']
        );

        $this->assertStringContainsString('IOReadBandwidthMax=/dev/manual 9M', $out);
        $this->assertStringNotContainsString('IOReadBandwidthMax=/dev/testhome 25M', $out);
    }

    public function testIoProfileOverridesPolicyMountIoPairs(): void
    {
        $out = $this->pmssRunUserConfigCgroupCliWithPolicy(
            ['mounts' => ['/home' => ['readBw' => '25M']]],
            ['root', '--apply', '--dry-run', '--defaults', '--device=/dev/manual', '--io-profile=hdd'],
            ['PMSS_HOME_DEVICE' => '/dev/testhome']
        );

        $this->assertStringContainsString('IOReadBandwidthMax=/dev/manual 5M', $out);
        $this->assertStringNotContainsString('IOReadBandwidthMax=/dev/testhome 25M', $out);
    }
}
