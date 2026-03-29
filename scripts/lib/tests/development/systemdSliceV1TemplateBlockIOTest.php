<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class SystemdSliceV1TemplateBlockIOTest extends TestCase
{
    public function testV1TemplateIncludesBlockIOAccounting(): void
    {
        $v1Body = "[Slice]\nBlockIOAccounting=yes\nTasksMax=%%USER_CGROUP_TASKS_MAX%%\n";
        $out = $this->pmssSystemdSliceDropinRender($this->pmssSystemdSliceFixturePrepare([
            'cfgPrefix' => 'pmss-cg-cfgv1b-',
            'dropPrefix' => 'pmss-cg-dropv1b-',
            'mode' => 'v1',
            'v1Template' => $v1Body,
            'totalMemMiB' => 4096,
        ]));
        $this->assertTrue(strpos($out, 'BlockIOAccounting=yes') !== false, 'v1 template lost BlockIOAccounting');
    }
}
