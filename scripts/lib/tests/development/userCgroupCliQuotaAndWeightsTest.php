<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/UserConfigCgroupCliTrait.php';

class UserCgroupCliQuotaAndWeightsTest extends TestCase
{
    use UserConfigCgroupCliTrait;

    public function testCgroupCliPlansExpectedQuotaAndWeights(): void
    {
        foreach ([
            [['root', '--apply', '--dry-run', '--memory-high=500', '--cpu-quota-percent=70'], [], ['CPUQuota=70%']],
            // 1600 MiB derives weight 320; derived IOWeight is capped at 200 for BFQ.
            [['root', '--apply', '--dry-run', '--memory-high=1600'], [], ['CPUWeight=320', 'IOWeight=200']],
            [['root', '--apply', '--dry-run', '--memory-high=1600', '--cpu-weight=50'], [], ['CPUWeight=50', 'IOWeight=200']],
            [['root', '--apply', '--dry-run', '--device=/home', '--io-profile=bulk'], ['PMSS_HOME_DEVICE' => '/dev/null'], ['IOWeight=500', 'CPUWeight=300', 'TasksMax=8192']],
        ] as [$argv, $env, $expectedFragments]) {
            $out = $this->pmssRunUserConfigCgroupCli($argv, $env);
            foreach ($expectedFragments as $fragment) {
                $this->assertStringContainsString($fragment, $out);
            }
        }
    }
}
