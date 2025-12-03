<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/cgroup/SystemInterface.php';
require_once dirname(__DIR__, 2).'/cgroup/Manager.php';

use PMSS\Cgroup\Manager;
use PMSS\Cgroup\SystemInterface;

class UserCgroupUtilTest extends TestCase
{
    /**
     * Create a manager instance backed by a no-op system implementation.
     *
     * computeSetProps does not depend on the SystemInterface, but wiring
     * a stub keeps the construction path identical to real usage while
     * remaining hermetic.
     */
    private function makeManager(): Manager
    {
        $stub = new class implements SystemInterface {
            public function getCgroupMode(): string { return 'v2'; }
            public function getUid(string $user): int { return 1000; }
            public function execute(string $command): ?string { return ''; }
            public function readFile(string $path): ?string { return null; }
            public function getTotalMemoryMiB(): int { return 0; }
            public function resolveDevice(string $device): string { return ''; }
            public function requireRoot(): void {}
        };

        return new Manager($stub);
    }

    public function testComputePropsClampsMemory(): void
    {
        $mgr = $this->makeManager();
        $sys = 4096; // MiB
        $p = $mgr->computeSetProps(['memory-high' => 100, 'memory-max' => 10000], $sys);
        $this->assertEquals('250M', $p['MemoryHigh']);
        $this->assertTrue((int)rtrim($p['MemoryMax'],'M') <= (int)floor($sys*0.95));
        $this->assertTrue((int)rtrim($p['MemoryMax'],'M') >= 250);
    }

    public function testComputePropsDerivesMaxFromHighWhenMaxMissing(): void
    {
        $mgr = $this->makeManager();
        $sys = 8192;
        $p = $mgr->computeSetProps(['memory-high' => 500], $sys);
        $mh = (int)rtrim($p['MemoryHigh'],'M');
        $mm = (int)rtrim($p['MemoryMax'],'M');
        $this->assertTrue($mm >= (int)floor($mh*1.4), 'expected ~1.5x high');
        $this->assertTrue($mm <= (int)floor($sys*0.95));
    }

    public function testComputePropsAcceptsCpuIoTasks(): void
    {
        $mgr = $this->makeManager();
        $p = $mgr->computeSetProps(['cpu-weight'=>300,'io-weight'=>250,'tasks-max'=>8192], 32768);
        $this->assertEquals(300, $p['CPUWeight']);
        $this->assertEquals(250, $p['IOWeight']);
        $this->assertEquals(8192, $p['TasksMax']);
    }

    public function testComputePropsDefaultsHighWhenOnlyMaxProvided(): void
    {
        $mgr = $this->makeManager();
        $p = $mgr->computeSetProps(['memory-max'=>3000], 16000);
        $this->assertTrue(isset($p['MemoryHigh']), 'MemoryHigh should be set when only max provided');
        $this->assertTrue((int)rtrim($p['MemoryMax'],'M') >= (int)rtrim($p['MemoryHigh'],'M'));
    }

    public function testComputePropsHandlesZeroSysMemGracefully(): void
    {
        $mgr = $this->makeManager();
        $p = $mgr->computeSetProps(['memory-high'=>250,'memory-max'=>500], 0);
        $this->assertEquals('250M', $p['MemoryHigh']);
        $this->assertEquals('500M', $p['MemoryMax']);
    }

    public function testComputePropsSupportsCpuQuota(): void
    {
        $mgr = $this->makeManager();
        $withQuota = $mgr->computeSetProps(['memory-high'=>400,'cpu-quota-percent'=>85], 16000);
        $this->assertEquals('85%', $withQuota['CPUQuota']);
        $infinity = $mgr->computeSetProps(['memory-high'=>400,'cpu-quota-percent'=>0], 16000);
        $this->assertEquals('', $infinity['CPUQuota']);
    }
}
