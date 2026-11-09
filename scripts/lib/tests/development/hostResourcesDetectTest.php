<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class hostResourcesDetectTest extends TestCase
{
    public function setUp(): void
    {
        $this->pmssTrackEnvKeys(['PMSS_TOTAL_MEM_MIB', 'PMSS_TOTAL_CPU_THREADS']);
    }

    public function testCpuInfoThreadCountMatchesOnlyProcessorFields(): void
    {
        // Exercise the pure parser in systemPrep/hostEnvironment.php without reading host files.
        foreach ([
            ['', 0],
            ["model name\t: Common KVM processor\n", 0],
            ["processor\t: 0\nmodel name\t: Example Processor\n", 1],
            ["processor: 0\nprocessor : 1\nprocessor\t: 2\n", 3],
            ["processor\t: 0\r\n\r\nprocessor\t: 1\r\n", 2],
            ["processor: 0", 1],
            ["model name: processor: 0\nprocessor count: 8\ncoprocessor: 1\n", 0],
        ] as $case) {
            $this->assertSame($case[1], \pmssCpuInfoThreadCount($case[0]));
        }
    }

    public function testKvmModelNameDoesNotDoubleHostCpuQuota(): void
    {
        $cpuinfo = '';
        foreach (range(0, 5) as $thread) {
            $cpuinfo .= "processor\t: ".$thread."\nmodel name\t: Common KVM processor\n\n";
        }

        // Six logical CPUs must size the 85% host default to 510%, rather than 1020%.
        $threads = \pmssCpuInfoThreadCount($cpuinfo);
        $this->assertSame(6, $threads);
        $sizing = \pmssSystemdSliceSizing([], 16384, $threads);
        $this->assertSame('510%', $sizing['%%USER_CGROUP_CPU_QUOTA%%']);
    }

    public function testResourceOverrideMatrix(): void
    {
        foreach ([
            ['PMSS_TOTAL_MEM_MIB', '16384', '\pmssTotalMemMiB', 16384],
            ['PMSS_TOTAL_MEM_MIB', '0', '\pmssTotalMemMiB', 0],
            ['PMSS_TOTAL_MEM_MIB', '', '\pmssTotalMemMiB', null],
            ['PMSS_TOTAL_MEM_MIB', 'invalid', '\pmssTotalMemMiB', null],
            ['PMSS_TOTAL_CPU_THREADS', '24', '\pmssTotalCpuThreads', 24],
            ['PMSS_TOTAL_CPU_THREADS', '0', '\pmssTotalCpuThreads', 0],
            ['PMSS_TOTAL_CPU_THREADS', 'invalid', '\pmssTotalCpuThreads', null],
        ] as $case) {
            putenv($case[0].'='.$case[1]);
            $actual = call_user_func($case[2]);
            $case[3] === null
                ? $this->assertTrue($actual >= 0, 'Expected fallback for '.$case[0])
                : $this->assertEquals($case[3], $actual, 'Expected override for '.$case[0]);
        }
    }
}
