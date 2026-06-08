<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class FuseOverlayfsSupportTest extends TestCase
{
    public function testFuseOverlayfsContractsStayCentralized(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/distUpgrade/docker.php', [
            'pmssEnsureFuseOverlayfsAfterDistUpgrade',
            'apt-cache show fuse-overlayfs',
            "pmssDistUpgradeAptCommand(\$env, 'install', 'fuse-overlayfs')",
            'fuse-overlayfs',
        ]);
        $this->pmssAssertRepoFileContainsString('scripts/lib/update/distUpgrade/apt.php', 'apt-get install');
        $this->pmssAssertRepoFileContainsString('scripts/lib/update/userMaintenance.php', "require_once __DIR__.'/users/docker.php';");
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/users/docker.php', [
            'fuse-overlayfs',
            'disable_containerd_snapshotter',
        ], [
            'if ($distroVersion >= 12)' => 'Expected fuse-overlayfs enforcement to apply on Debian 12+ when available',
        ]);
        $this->pmssAssertRepoFileContainsString('scripts/lib/user/rootlessDockerConfig.php', 'containerd-snapshotter');
    }
}
