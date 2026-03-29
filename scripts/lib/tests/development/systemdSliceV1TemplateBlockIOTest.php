<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class SystemdSliceV1TemplateBlockIOTest extends TestCase
{
    public function testV1TemplateIncludesBlockIOAccounting(): void
    {
        $out = $this->pmssSystemdSliceRender([
            'cfgPrefix' => 'pmss-cg-cfgv1b-',
            'dropPrefix' => 'pmss-cg-dropv1b-',
            'mode' => 'v1',
            'v1Template' => $this->pmssSystemdSliceTasksTemplate(['BlockIOAccounting=yes']),
            'totalMemMiB' => 4096,
        ]);
        $this->assertTrue(strpos($out, 'BlockIOAccounting=yes') !== false, 'v1 template lost BlockIOAccounting');
    }
}
