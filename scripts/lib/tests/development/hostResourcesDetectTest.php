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

    public function testResourceOverrideMatrix(): void
    {
        foreach ([
            ['PMSS_TOTAL_MEM_MIB', '16384', '\pmssTotalMemMiB', 16384],
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
