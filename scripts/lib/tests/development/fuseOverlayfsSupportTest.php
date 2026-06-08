<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class FuseOverlayfsSupportTest extends TestCase
{
    public function testFuseOverlayfsContractsStayCentralized(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/update/distUpgrade/docker.php' => ['required' => [
                'pmssEnsureFuseOverlayfsAfterDistUpgrade',
                'apt-cache show fuse-overlayfs',
                "pmssDistUpgradeAptCommand(\$env, 'install', 'fuse-overlayfs')",
                'fuse-overlayfs',
            ]],
            'scripts/lib/update/distUpgrade/apt.php' => ['required' => ['apt-get install']],
            'scripts/lib/update/userMaintenance.php' => ['required' => ["require_once __DIR__.'/users/docker.php';"]],
            'scripts/lib/update/users/docker.php' => [
                'required' => ['fuse-overlayfs', 'disable_containerd_snapshotter'],
                'forbidden' => [
                    'if ($distroVersion >= 12)' => 'Expected fuse-overlayfs enforcement to apply on Debian 12+ when available',
                ],
            ],
            'scripts/lib/user/rootlessDockerConfig.php' => ['required' => ['containerd-snapshotter']],
        ]);
    }
}
