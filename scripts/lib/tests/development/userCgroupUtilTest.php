<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/util/userCgroup.php';

class UserCgroupUtilTest extends TestCase
{
    public function testComputePropsClampsMemory(): void
    {
        $sys = 4096; // MiB
        $p = \computeSetProps(['memory-high' => 100, 'memory-max' => 10000], $sys);
        $this->assertEquals('250M', $p['MemoryHigh']);
        $this->assertTrue((int)rtrim($p['MemoryMax'],'M') <= (int)floor($sys*0.95));
        $this->assertTrue((int)rtrim($p['MemoryMax'],'M') >= 250);
    }

    public function testComputePropsDerivesMaxFromHighWhenMaxMissing(): void
    {
        $sys = 8192;
        $p = \computeSetProps(['memory-high' => 500], $sys);
        $mh = (int)rtrim($p['MemoryHigh'],'M');
        $mm = (int)rtrim($p['MemoryMax'],'M');
        $this->assertTrue($mm >= (int)floor($mh*1.4), 'expected ~1.5x high');
        $this->assertTrue($mm <= (int)floor($sys*0.95));
    }

    public function testComputePropsAcceptsCpuIoTasks(): void
    {
        $p = \computeSetProps(['cpu-weight'=>300,'io-weight'=>250,'tasks-max'=>8192], 32768);
        $this->assertEquals(300, $p['CPUWeight']);
        $this->assertEquals(250, $p['IOWeight']);
        $this->assertEquals(8192, $p['TasksMax']);
    }

    public function testComputePropsDefaultsHighWhenOnlyMaxProvided(): void
    {
        $p = \computeSetProps(['memory-max'=>3000], 16000);
        $this->assertTrue(isset($p['MemoryHigh']), 'MemoryHigh should be set when only max provided');
        $this->assertTrue((int)rtrim($p['MemoryMax'],'M') >= (int)rtrim($p['MemoryHigh'],'M'));
    }

    public function testComputePropsHandlesZeroSysMemGracefully(): void
    {
        $p = \computeSetProps(['memory-high'=>250,'memory-max'=>500], 0);
        $this->assertEquals('250M', $p['MemoryHigh']);
        $this->assertEquals('500M', $p['MemoryMax']);
    }
}

