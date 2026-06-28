<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/UserConfigCgroupCliTrait.php';

class UserCgroupPolicyProfilesTest extends TestCase
{
    use UserConfigCgroupCliTrait;

    public function testCpuProfileUsesPolicyFamilyWhenDefined(): void
    {
        $output = $this->pmssRunUserConfigCgroupCliWithPolicy([
            'profiles' => [
                'cpu' => ['balanced' => 180],
            ],
        ], ['root', '--apply', '--dry-run', '--cpu-profile=balanced']);

        $this->assertStringContainsString('CPUWeight=180', $output);
    }

    public function testTasksProfileUsesPolicyFamilyWhenDefined(): void
    {
        $output = $this->pmssRunUserConfigCgroupCliWithPolicy([
            'profiles' => [
                'tasks' => ['service' => 12000],
            ],
        ], ['root', '--apply', '--dry-run', '--tasks-profile=service']);

        $this->assertStringContainsString('TasksMax=12000', $output);
    }

    public function testMemProfileUsesPolicyFamilyWhenDefined(): void
    {
        $output = $this->pmssRunUserConfigCgroupCliWithPolicy([
            'profiles' => [
                'mem' => ['streaming' => 1536],
            ],
        ], ['root', '--apply', '--dry-run', '--mem-profile=streaming']);

        $this->assertStringContainsString('MemoryHigh=1536M', $output);
        $this->assertStringContainsString('MemoryMax=', $output);
    }

    public function testBuiltInProfileStillWorksWithoutPolicyFamily(): void
    {
        $output = $this->pmssRunUserConfigCgroupCliWithPolicy([], ['root', '--apply', '--dry-run', '--cpu-profile=low']);

        $this->assertStringContainsString('CPUWeight=50', $output);
    }

    public function testInvalidPolicyProfileValueFallsBackToBuiltIn(): void
    {
        $output = $this->pmssRunUserConfigCgroupCliWithPolicy([
            'profiles' => [
                'cpu' => ['low' => 'invalid'],
            ],
        ], ['root', '--apply', '--dry-run', '--cpu-profile=low']);

        $this->assertStringContainsString('CPUWeight=50', $output);
    }

    public function testIoProfileUsesPolicyFamilyWhenDefined(): void
    {
        $output = $this->pmssRunUserConfigCgroupCliWithPolicy([
            'profiles' => [
                'io' => [
                    'hdd' => [
                        'ioWeight' => 240,
                        'readBw' => '7M',
                        'writeBw' => '13M',
                        'readIops' => 77,
                        'writeIops' => 88,
                    ],
                ],
            ],
        ], ['root', '--apply', '--dry-run', '--device=/dev/sda', '--io-profile=hdd']);

        $this->assertStringContainsAllStrings(['IOWeight=240', 'IOReadBandwidthMax=/dev/sda 7M', 'IOWriteBandwidthMax=/dev/sda 13M', 'IOReadIOPSMax=/dev/sda 77', 'IOWriteIOPSMax=/dev/sda 88'], $output);
    }

    public function testIoProfilePolicyCanDefineCustomProfile(): void
    {
        $output = $this->pmssRunUserConfigCgroupCliWithPolicy([
            'profiles' => [
                'io' => [
                    'archive' => [
                        'ioWeight' => 150,
                        'readBw' => '3M',
                        'writeBw' => '4M',
                        'readIops' => 30,
                        'writeIops' => 40,
                    ],
                ],
            ],
        ], ['root', '--apply', '--dry-run', '--device=/dev/sda', '--io-profile=archive']);

        $this->assertStringContainsAllStrings(['IOWeight=150', 'IOReadBandwidthMax=/dev/sda 3M', 'IOWriteBandwidthMax=/dev/sda 4M', 'IOReadIOPSMax=/dev/sda 30', 'IOWriteIOPSMax=/dev/sda 40'], $output);
    }

    public function testIoProfilePolicyCanOverrideBulkCpuAndTasksDefaults(): void
    {
        $output = $this->pmssRunUserConfigCgroupCliWithPolicy([
            'profiles' => [
                'io' => [
                    'bulk' => [
                        'ioWeight' => 333,
                        'cpuWeight' => 444,
                        'tasksMax' => 9999,
                    ],
                ],
            ],
        ], ['root', '--apply', '--dry-run', '--device=/dev/sda', '--io-profile=bulk']);

        $this->assertStringContainsAllStrings(['IOWeight=333', 'CPUWeight=444', 'TasksMax=9999'], $output);
    }
}
