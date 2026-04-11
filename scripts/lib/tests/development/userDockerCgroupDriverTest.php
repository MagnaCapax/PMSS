<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserDockerCgroupDriverTest extends TestCase
{
    public function testSkelSeedsCgroupfsDriverOverride(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/skel/.config/docker/daemon.json',
            ['"exec-opts"', '"native.cgroupdriver=cgroupfs"']
        );
    }

    public function testUserDockerBackfillsCgroupV2DaemonConfigBeforeStart(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/util/userDocker.php',
            [
                "require_once __DIR__.'/../lib/user/directories.php';",
                'function userDockerCgroupMode(): string',
                "getenv('PMSS_CGROUP_MODE')",
                "'/sys/fs/cgroup/cgroup.controllers'",
                "'.config/docker'",
                "'native.cgroupdriver=cgroupfs'",
                'userDockerEnsureCgroupfsDaemonConfig($user, $home, $uid, (int) $info[\'gid\']);',
                'userDocker: wrote ~/.config/docker/daemon.json for cgroup v2 rootless Docker',
                'userDocker: updated ~/.config/docker/daemon.json for cgroup v2 rootless Docker',
            ]
        );
    }
}
