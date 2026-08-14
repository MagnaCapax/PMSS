<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class cgroupModeDetectTest extends TestCase
{
    public function testCgroupModeOverrideMatrix(): void
    {
        foreach (['v1' => 'v1', 'v2' => 'v2', 'invalid' => null] as $override => $expected) {
            $this->pmssWithEnv(['PMSS_CGROUP_MODE' => $override], function () use ($expected): void {
                $mode = \pmssCgroupMode();
                $expected === null
                    ? $this->assertTrue(in_array($mode, ['v1', 'v2', 'unknown'], true))
                    : $this->assertEquals($expected, $mode);
            });
        }
    }

    public function testCgroupModeFromSignalsDecisionTable(): void
    {
        // #707: the pure decision helper — pin-first (authoritative), then cgroup.controllers at
        // the hierarchy ROOT for genuine v2. Covers the real detection logic the override matrix
        // above never exercises.
        // v1-hybrid: pin present AND cgroup2 controllers at root -> pin wins -> v1 (the bug fix).
        $this->assertSame('v1', \pmssCgroupModeFromSignals('quiet systemd.unified_cgroup_hierarchy=0 ro', true, ['/sys/fs/cgroup/unified']));
        // pin present, no controllers -> v1.
        $this->assertSame('v1', \pmssCgroupModeFromSignals('systemd.unified_cgroup_hierarchy=0', false, []));
        // no pin, controllers at root -> genuine unified v2.
        $this->assertSame('v2', \pmssCgroupModeFromSignals('quiet ro', true, []));
        // no pin (e.g. v2 drift with pin missing from cmdline), no root controllers, v1 dirs -> v1.
        $this->assertSame('v1', \pmssCgroupModeFromSignals('quiet ro', false, ['/sys/fs/cgroup/blkio', '/sys/fs/cgroup/unified']));
        // only the hybrid 'unified' dir, no pin, no controllers -> unknown (ambiguous).
        $this->assertSame('unknown', \pmssCgroupModeFromSignals(null, false, ['/sys/fs/cgroup/unified']));
        // no signals at all -> unknown.
        $this->assertSame('unknown', \pmssCgroupModeFromSignals(null, false, []));
    }
}
