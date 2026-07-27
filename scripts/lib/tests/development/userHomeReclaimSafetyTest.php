<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserHomeReclaimSafetyTest extends TestCase
{
    public function testReclaimTargetIsRevalidatedAfterImmutableClear(): void
    {
        $path = 'scripts/lib/user/homeReclaimWorker.php';

        $this->pmssAssertRepoFileContainsString(
            $path,
            'function pmssUserHomeReclaimRefuseUnsafePath(',
            'homeReclaimWorker.php should centralize unsafe path refusal'
        );
        $this->pmssAssertRepoFileMatches(
            $path,
            '/home_reclaim_clear_immutable.*?clearstatcache\(true, \$targetPath\);'
                .'.*?if \(!pmssUserHomeReclaimPathIsSafe\(\$targetPath\)\) \{'
                .'.*?home_reclaim_unsafe_after_immutable_clear'
                .'.*?home_reclaim_delete_contents/s',
            'homeReclaimWorker.php should revalidate the target after chattr and before find -delete'
        );
    }
}
