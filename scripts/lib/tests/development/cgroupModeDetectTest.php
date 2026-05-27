<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class cgroupModeDetectTest extends TestCase
{
    public function setUp(): void
    {
        $this->pmssTrackEnvKeys(['PMSS_CGROUP_MODE']);
    }

    public function testCgroupModeOverrideMatrix(): void
    {
        foreach (['v1' => 'v1', 'v2' => 'v2', 'invalid' => null] as $override => $expected) {
            putenv('PMSS_CGROUP_MODE='.$override);
            $mode = \pmssCgroupMode();
            $expected === null
                ? $this->assertTrue(in_array($mode, ['v1', 'v2', 'unknown'], true))
                : $this->assertEquals($expected, $mode);
        }
    }
}
