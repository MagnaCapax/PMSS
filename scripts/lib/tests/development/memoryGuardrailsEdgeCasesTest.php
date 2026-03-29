<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class MemoryGuardrailsEdgeCasesTest extends TestCase
{
    public function testLowRamFloorsAreApplied(): void
    {
        $tpl = "[Slice]\nMemoryHigh=%%USER_CGROUP_MEMORY_HIGH%%M\nMemoryMax=%%USER_CGROUP_MEMORY_MAX%%M\n";
        $content = $this->pmssSystemdSliceDropinRender($this->pmssSystemdSliceFixturePrepare([
            'v2Template' => $tpl,
            'totalMemMiB' => 512,
        ]));
        preg_match('/MemoryHigh=(\d+)M/', $content, $m1);
        preg_match('/MemoryMax=(\d+)M/', $content, $m2);
        $this->assertTrue(((int) $m1[1]) >= 250, 'MemoryHigh floor not enforced');
        $this->assertTrue(((int) $m2[1]) <= (int) floor(512 * 0.95), 'MemoryMax cap not enforced');
    }

    public function testHugeRamRespects95PercentCap(): void
    {
        $tpl = "[Slice]\nMemoryHigh=%%USER_CGROUP_MEMORY_HIGH%%M\nMemoryMax=%%USER_CGROUP_MEMORY_MAX%%M\n";
        $content = $this->pmssSystemdSliceDropinRender($this->pmssSystemdSliceFixturePrepare([
            'cfgPrefix' => 'pmss-cg-cfg2-',
            'dropPrefix' => 'pmss-cg-drop2-',
            'v2Template' => $tpl,
            'totalMemMiB' => 65536,
        ]));
        preg_match('/MemoryMax=(\d+)M/', $content, $m);
        $this->assertTrue(((int) $m[1]) <= (int) floor(65536 * 0.95));
    }

    public function testDefaultBurstabilityAlignsTo25Percent(): void
    {
        $tpl = "[Slice]\nMemoryHigh=%%USER_CGROUP_MEMORY_HIGH%%M\nMemoryMax=%%USER_CGROUP_MEMORY_MAX%%M\n";
        $content = $this->pmssSystemdSliceDropinRender($this->pmssSystemdSliceFixturePrepare([
            'cfgPrefix' => 'pmss-cg-cfg3-',
            'dropPrefix' => 'pmss-cg-drop3-',
            'v2Template' => $tpl,
            'totalMemMiB' => 10240,
        ]));
        preg_match('/MemoryHigh=(\d+)M/', $content, $m1);
        preg_match('/MemoryMax=(\d+)M/', $content, $m2);

        $high = (int) ($m1[1] ?? 0);
        $max = (int) ($m2[1] ?? 0);
        $this->assertTrue($high > 0 && $max > 0, 'Failed to parse MemoryHigh/MemoryMax from drop-in');
        $this->assertEquals((int) floor($high * 1.25), $max, 'Expected default MemoryMax to be 25% above MemoryHigh');
    }
}
