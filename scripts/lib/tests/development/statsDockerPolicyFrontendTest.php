<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class StatsDockerPolicyFrontendTest extends TestCase
{
    public function testStatsPageDetectsUserConfigStoreTraversalFailures(): void
    {
        $src = $this->pmssReadRepoFile('etc/skel/www/stats.php');

        $this->assertTrue(strpos($src, 'function pmssInfoDockerPolicyStoreState()') !== false);
        $this->assertTrue(strpos($src, "array('/scripts', '/scripts/lib', '/scripts/lib/user')") !== false);
        $this->assertTrue(strpos($src, "'reason' => 'permission'") !== false);
    }

    public function testStatsPageShowsPlatformManagedNoticeForPermissionFailures(): void
    {
        $src = $this->pmssReadRepoFile('etc/skel/www/stats.php');

        $this->assertTrue(strpos($src, 'Docker policy changes from the web panel are unavailable on this host.') !== false);
        $this->assertTrue(strpos($src, "\$pmssDockerPolicyStoreState['reason'] === 'permission'") !== false);
        $this->assertTrue(strpos($src, "pmssInfoDockerPolicyUnavailableMessage(\$pmssDockerPolicyStoreState['reason'])") !== false);
    }

    public function testStatsPageDisablesBrokenDockerToggleWhenStoreIsUnavailable(): void
    {
        $src = $this->pmssReadRepoFile('etc/skel/www/stats.php');

        $this->assertTrue(strpos($src, "\$pmssDockerToggleDisabled = \$pmssStatsUsername === '' || !\$pmssDockerPolicyStoreState['available'];") !== false);
        $this->assertTrue(substr_count($src, "\$pmssDockerToggleDisabled ? 'disabled' : ''") === 2);
    }
}
