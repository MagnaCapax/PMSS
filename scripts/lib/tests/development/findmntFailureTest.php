<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class FindmntFailureTest extends TestCase
{
    public function testUnresolvableMountSkipsIoAppends(): void
    {
        $out = $this->pmssSystemdSliceRender([
            'v2Template' => $this->pmssSystemdSliceTasksTemplate(),
            'policy' => [
                'tasksMax' => 512,
                'mounts' => ['/nonexistent-mount-xyz' => ['readBw' => '7M', 'writeBw' => '9M']],
            ],
            'totalMemMiB' => 2048,
        ]);

        $this->assertStringNotContainsString('IOReadBandwidthMax=', $out, 'unexpected IOReadBandwidthMax append');
        $this->assertStringNotContainsString('IOWriteBandwidthMax=', $out, 'unexpected IOWriteBandwidthMax append');
    }
}
