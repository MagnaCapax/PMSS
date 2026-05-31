<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class SystemdSliceRootOverrideWrittenTest extends TestCase
{
    public function testRootUnlimitedDropinCreated(): void
    {
        $base = $this->pmssMakeTempDir('pmss-cg-basedir-');
        $drop = $base.'/user-.slice.d';
        @mkdir($drop, 0755, true);

        $fixture = $this->pmssSystemdSliceFixturePrepare([
            'dropDir' => $drop,
            'v2Template' => $this->pmssSystemdSliceTasksTemplate(),
            'totalMemMiB' => 1024,
        ]);

        $this->pmssSystemdSliceEnsure($fixture);

        $rootDrop = $base.'/user-0.slice.d/99-zz-pmss-unlimited.conf';
        $this->pmssAssertFileContainsAllStrings($rootDrop, [
            'TasksMax=infinity',
            'MemoryHigh=infinity',
            'MemoryMax=infinity',
        ], 'Root override missing');
    }
}
