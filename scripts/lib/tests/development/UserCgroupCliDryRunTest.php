<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserCgroupCliDryRunTest extends TestCase
{
    public function testDryRunPrintsPlannedPropsAndIo(): void
    {
        $out = @shell_exec('php scripts/util/userCgroup.php root --dry-run --cpu-weight=300 --io-weight=250 --tasks-max=9000 --memory-high=600 --memory-max=900 --io-read-bw=/dev/sda:5M --io-write-iops=/dev/sda:100');
        $this->assertTrue(is_string($out) && $out !== '', 'no output');
        $this->assertTrue(strpos($out, 'CPUWeight=300') !== false);
        $this->assertTrue(strpos($out, 'IOWeight=250') !== false);
        $this->assertTrue(strpos($out, 'TasksMax=9000') !== false);
        $this->assertTrue(strpos($out, 'MemoryHigh=') !== false);
        $this->assertTrue(strpos($out, 'MemoryMax=') !== false);
        $this->assertTrue(strpos($out, 'IOReadBandwidthMax=/dev/sda 5M') !== false);
        $this->assertTrue(strpos($out, 'IOWriteIOPSMax=/dev/sda 100') !== false);
    }
}

