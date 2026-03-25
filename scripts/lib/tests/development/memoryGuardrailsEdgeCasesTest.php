<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class MemoryGuardrailsEdgeCasesTest extends TestCase
{
    public function testLowRamFloorsAreApplied(): void
    {
        $cfgDir = $this->pmssMakeTempDir('pmss-cg-cfg-');
        $drop   = $this->pmssMakeTempDir('pmss-cg-drop-');
        $tpl = "[Slice]\nMemoryHigh=%%USER_CGROUP_MEMORY_HIGH%%M\nMemoryMax=%%USER_CGROUP_MEMORY_MAX%%M\n";
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v2.conf', $tpl);
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v1.conf', 'ignored');

        $this->pmssWithEnv([
            'PMSS_CGROUP_MODE' => 'v2',
            'PMSS_CONFIG_DIR' => $cfgDir,
            'PMSS_SYSTEMD_USER_SLICE_DIR' => $drop,
            'PMSS_TOTAL_MEM_MIB' => '512',
        ], function () use ($drop): void {
            \pmssEnsureSystemdSlices('logmsg');
            $content = (string)file_get_contents($drop.'/15-pmss.conf');
            preg_match('/MemoryHigh=(\d+)M/', $content, $m1);
            preg_match('/MemoryMax=(\d+)M/', $content, $m2);
            $this->assertTrue(((int)$m1[1]) >= 250, 'MemoryHigh floor not enforced');
            $this->assertTrue(((int)$m2[1]) <= (int)floor(512*0.95), 'MemoryMax cap not enforced');
        });
    }

    public function testHugeRamRespects95PercentCap(): void
    {
        $cfgDir = $this->pmssMakeTempDir('pmss-cg-cfg2-');
        $drop   = $this->pmssMakeTempDir('pmss-cg-drop2-');
        $tpl = "[Slice]\nMemoryHigh=%%USER_CGROUP_MEMORY_HIGH%%M\nMemoryMax=%%USER_CGROUP_MEMORY_MAX%%M\n";
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v2.conf', $tpl);
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v1.conf', 'ignored');

        $this->pmssWithEnv([
            'PMSS_CGROUP_MODE' => 'v2',
            'PMSS_CONFIG_DIR' => $cfgDir,
            'PMSS_SYSTEMD_USER_SLICE_DIR' => $drop,
            'PMSS_TOTAL_MEM_MIB' => '65536',
        ], function () use ($drop): void {
            \pmssEnsureSystemdSlices('logmsg');
            $content = (string)file_get_contents($drop.'/15-pmss.conf');
            preg_match('/MemoryMax=(\d+)M/', $content, $m);
            $this->assertTrue(((int)$m[1]) <= (int)floor(65536*0.95));
        });
    }

    public function testDefaultBurstabilityAlignsTo25Percent(): void
    {
        $cfgDir = $this->pmssMakeTempDir('pmss-cg-cfg3-');
        $drop   = $this->pmssMakeTempDir('pmss-cg-drop3-');
        $tpl = "[Slice]\nMemoryHigh=%%USER_CGROUP_MEMORY_HIGH%%M\nMemoryMax=%%USER_CGROUP_MEMORY_MAX%%M\n";
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v2.conf', $tpl);
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v1.conf', 'ignored');

        $this->pmssWithEnv([
            'PMSS_CGROUP_MODE' => 'v2',
            'PMSS_CONFIG_DIR' => $cfgDir,
            'PMSS_SYSTEMD_USER_SLICE_DIR' => $drop,
            'PMSS_TOTAL_MEM_MIB' => '10240',
        ], function () use ($drop): void {
            \pmssEnsureSystemdSlices('logmsg');
            $content = (string)file_get_contents($drop.'/15-pmss.conf');
            preg_match('/MemoryHigh=(\d+)M/', $content, $m1);
            preg_match('/MemoryMax=(\d+)M/', $content, $m2);

            $high = (int)($m1[1] ?? 0);
            $max  = (int)($m2[1] ?? 0);
            $this->assertTrue($high > 0 && $max > 0, 'Failed to parse MemoryHigh/MemoryMax from drop-in');
            $this->assertEquals((int)floor($high * 1.25), $max, 'Expected default MemoryMax to be 25% above MemoryHigh');
        });
    }
}
