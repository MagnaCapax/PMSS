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

    public function testOverrideSelectsV1(): void
    {
        putenv('PMSS_CGROUP_MODE=v1');
        $this->assertEquals('v1', \pmssCgroupMode());
    }

    public function testOverrideSelectsV2(): void
    {
        putenv('PMSS_CGROUP_MODE=v2');
        $this->assertEquals('v2', \pmssCgroupMode());
    }

    public function testInvalidOverrideFallsBackToKnownModes(): void
    {
        putenv('PMSS_CGROUP_MODE=invalid');
        $mode = \pmssCgroupMode();
        $this->assertTrue(in_array($mode, ['v1', 'v2', 'unknown'], true));
    }
}
