<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep/hostResourcesDetect.php';

class hostResourcesDetectTest extends TestCase
{
    /** @var string|false */
    private $originalTotalMem;

    /** @var string|false */
    private $originalTotalCpuThreads;

    public function setUp(): void
    {
        $this->originalTotalMem = getenv('PMSS_TOTAL_MEM_MIB');
        $this->originalTotalCpuThreads = getenv('PMSS_TOTAL_CPU_THREADS');
    }

    public function tearDown(): void
    {
        if ($this->originalTotalMem === false) {
            putenv('PMSS_TOTAL_MEM_MIB');
        } else {
            putenv('PMSS_TOTAL_MEM_MIB='.$this->originalTotalMem);
        }

        if ($this->originalTotalCpuThreads === false) {
            putenv('PMSS_TOTAL_CPU_THREADS');
        } else {
            putenv('PMSS_TOTAL_CPU_THREADS='.$this->originalTotalCpuThreads);
        }
    }

    public function testTotalMemUsesNumericOverride(): void
    {
        putenv('PMSS_TOTAL_MEM_MIB=16384');
        $this->assertEquals(16384, \pmssTotalMemMiB());
    }

    public function testTotalMemInvalidOverrideFallsBack(): void
    {
        putenv('PMSS_TOTAL_MEM_MIB=invalid');
        $this->assertTrue(\pmssTotalMemMiB() >= 0);
    }

    public function testTotalCpuThreadsUsesNumericOverride(): void
    {
        putenv('PMSS_TOTAL_CPU_THREADS=24');
        $this->assertEquals(24, \pmssTotalCpuThreads());
    }

    public function testTotalCpuThreadsSupportsZeroOverride(): void
    {
        putenv('PMSS_TOTAL_CPU_THREADS=0');
        $this->assertEquals(0, \pmssTotalCpuThreads());
    }

    public function testTotalCpuThreadsInvalidOverrideFallsBack(): void
    {
        putenv('PMSS_TOTAL_CPU_THREADS=invalid');
        $this->assertTrue(\pmssTotalCpuThreads() >= 0);
    }
}
