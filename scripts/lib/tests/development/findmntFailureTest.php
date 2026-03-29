<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class FindmntFailureTest extends TestCase
{
    public function testUnresolvableMountSkipsIoAppends(): void
    {
        $tpl = "[Slice]\nTasksMax=%%USER_CGROUP_TASKS_MAX%%\n";
        $policy = <<<'PHP'
<?php return [
  'tasksMax'=>512,
  'mounts' => [ '/nonexistent-mount-xyz' => ['readBw'=>'7M','writeBw'=>'9M'] ],
];
PHP;

        $out = $this->pmssSystemdSliceDropinRender($this->pmssSystemdSliceFixturePrepare([
            'v2Template' => $tpl,
            'policy' => $policy,
            'totalMemMiB' => 2048,
        ]));

        $this->assertTrue(strpos($out, 'IOReadBandwidthMax=') === false, 'unexpected IOReadBandwidthMax append');
        $this->assertTrue(strpos($out, 'IOWriteBandwidthMax=') === false, 'unexpected IOWriteBandwidthMax append');
    }
}
