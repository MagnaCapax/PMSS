<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class SystemdSliceLegacyShadowUpdateTest extends TestCase
{
    public function testLegacyShadowFileRewrittenEvenWithoutVendorDropin(): void
    {
        $drop = $this->pmssMakeTempDir('pmss-cg-shadow-').'/user-.slice.d';
        $legacyShadow = $this->pmssWriteFile($drop.'/99-pmss.conf', "[Slice]\nTasksMax=1000\n");

        $this->pmssSystemdSliceEnsure($this->pmssSystemdSliceFixturePrepare([
            'dropDir' => $drop,
            'v2Template' => $this->pmssSystemdSliceTasksTemplate(),
            'totalCpuThreads' => 2,
            'totalMemMiB' => 1024,
        ]));

        $this->pmssAssertFileContainsAllStrings($legacyShadow, ['TasksMax=2048' => 'Legacy TasksMax not rewritten to expected floor clamp'],
            'Legacy shadow file missing after run');
    }
}
