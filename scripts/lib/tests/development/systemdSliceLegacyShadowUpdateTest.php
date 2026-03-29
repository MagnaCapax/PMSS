<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class SystemdSliceLegacyShadowUpdateTest extends TestCase
{
    public function testLegacyShadowFileRewrittenEvenWithoutVendorDropin(): void
    {
        $base = $this->pmssMakeTempDir('pmss-cg-shadow-');
        $drop = $base.'/user-.slice.d';
        @mkdir($drop, 0755, true);

        $legacyShadow = $drop.'/99-pmss.conf';
        file_put_contents($legacyShadow, "[Slice]\nTasksMax=1000\n");

        $this->pmssSystemdSliceEnsure($this->pmssSystemdSliceFixturePrepare([
            'cfgPrefix' => 'pmss-cg-cfg-shadow-',
            'dropDir' => $drop,
            'v2Template' => "[Slice]\nTasksMax=%%USER_CGROUP_TASKS_MAX%%\n",
            'totalCpuThreads' => 2,
            'totalMemMiB' => 1024,
        ]));

        $this->assertTrue(file_exists($legacyShadow), 'Legacy shadow file missing after run');
        $data = (string) file_get_contents($legacyShadow);
        $this->assertStringContainsString('TasksMax=2048', $data, 'Legacy TasksMax not rewritten to expected floor clamp');
    }
}
