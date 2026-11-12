<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/distUpgrade.php';

class FuseOverlayfsSupportTest extends TestCase
{
    public function testFuseOverlayfsContractsStayCentralized(): void
    {
        $this->assertSame('apt-get install -y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold fuse-overlayfs', \pmssDistUpgradeAptCommand('', 'install', 'fuse-overlayfs'));
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/distUpgrade/docker.php', [
            'pmssEnsureFuseOverlayfsAfterDistUpgrade',
            'apt-cache show fuse-overlayfs',
            "pmssDistUpgradeAptCommand(\$env, 'install', 'fuse-overlayfs')",
            'fuse-overlayfs',
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/userMaintenance.php', ["'users/docker.php'"]);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/users/dockerDependencies.php',
            ['fuse-overlayfs', 'disable_containerd_snapshotter'],
            ['if ($distroVersion >= 12)' => 'Expected fuse-overlayfs enforcement to apply on Debian 12+ when available']);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/user/rootlessDockerPolicy.php', ['containerd-snapshotter']);
    }
}
