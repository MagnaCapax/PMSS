<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/UserConfigCgroupCliTrait.php';

class UserCgroupCliDryRunTest extends TestCase
{
    use UserConfigCgroupCliTrait;

    public function testDryRunPrintsPlannedPropsAndIo(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli([
            'root',
            '--dry-run',
            '--cpu-weight=300',
            '--io-weight=250',
            '--tasks-max=9000',
            '--memory-high=600',
            '--memory-max=900',
            '--io-read-bw=/dev/sda:5M',
            '--io-write-iops=/dev/sda:100',
        ]);
        $this->assertTrue(is_string($out) && $out !== '', 'no output');
        $this->pmssAssertStringContract($out, ['required' => [
            'CPUWeight=300', 'IOWeight=250', 'TasksMax=9000', 'MemoryHigh=', 'MemoryMax=',
            'IOReadBandwidthMax=/dev/sda 5M', 'IOWriteIOPSMax=/dev/sda 100',
        ]]);
    }
}
