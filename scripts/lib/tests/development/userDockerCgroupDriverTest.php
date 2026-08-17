<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/rootlessDockerConfig.php';

class UserDockerCgroupDriverTest extends TestCase
{
    public function testUserDockerSourceContractsKeepRootlessConfigFlow(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'etc/skel/.config/docker/daemon.json' => ['required' => ['"exec-opts"', '"native.cgroupdriver=cgroupfs"']],
            'scripts/util/userDocker.php' => [
                'required' => [
                    "require_once __DIR__.'/../lib/user/rootlessDockerConfig.php';",
                    'function userDockerCgroupMode(): string',
                    "pmssCgroupModeWithDefault('v1')",
                    'pmssUserRootlessDockerConfigConverge($user, $home, $uid, $gid',
                    'userDockerEnsureCgroupfsDaemonConfig($user, $home, $uid, (int) $info[\'gid\']);',
                    'userDocker: wrote ~/.config/docker/daemon.json for cgroup v2 rootless Docker',
                    'userDocker: updated ~/.config/docker/daemon.json for cgroup v2 rootless Docker',
                    'unsafe_config_file',
                    'function userDockerSafeLabel(string $value): string',
                    'function userDockerAccountInfo(string $user, ?string &$reason = null): ?array',
                    'pmssValidateUsername($user)',
                    'pmssPasswdEntryPositiveUid($info) === null',
                    '$info = userDockerAccountInfo($user, $accountReason);',
                    '$safeUser = userDockerSafeLabel($user);',
                    '$target = userDockerAccountInfo($user);',
                    '$rc = 127;',
                ],
                'ordered' => [
                    [
                        'needles' => [
                            '$user = $args[1];',
                            '$info = userDockerAccountInfo($user, $accountReason);',
                            '$uid = (int) $info[\'uid\'];',
                            '$runtimeDir = "/run/user/{$uid}";',
                        ],
                        'missingPrefix' => 'missing userDocker CLI account guard: ',
                        'orderPrefix' => 'userDocker must validate account before runtime paths: ',
                    ],
                    [
                        'needles' => [
                            'function userDockerRunAs(',
                            'bool $placeInUserSlice = false',
                            '$target = userDockerAccountInfo($user);',
                            '$wrapper = pmssBuildUserShellCommand($user, $cmd);',
                        ],
                        'missingPrefix' => 'missing userDockerRunAs account guard: ',
                        'orderPrefix' => 'userDockerRunAs must validate account before shell builder: ',
                    ],
                ],
            ],
        ]);
    }

    public function testSharedRootlessDockerConfigCreatesCgroupfsOnlyConfig(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-');

        $result = \pmssUserRootlessDockerConfigConverge('alice', $home, 0, 0, [
            'create_when_missing' => true,
        ]);

        $this->pmssAssertArraySubsetSame(['ok' => true, 'changed' => true, 'created' => true], $result);
        $payload = $this->assertDaemonConfigContainsCgroupfs($home);
        $this->assertTrue(!array_key_exists('storage-driver', $payload));
    }

    public function testSharedRootlessDockerConfigPreservesCustomStorageDriver(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-');
        $this->writeDaemonConfig($home, ['storage-driver' => 'overlay2']);

        $result = \pmssUserRootlessDockerConfigConverge('alice', $home, 0, 0, [
            'storage_driver' => 'fuse-overlayfs',
            'create_when_missing' => true,
            'preserve_custom_storage_driver' => true,
        ]);

        $this->pmssAssertArraySubsetSame(['ok' => true, 'changed' => true, 'configured_storage_driver' => false], $result);
        $payload = $this->assertDaemonConfigContainsCgroupfs($home);
        $this->assertEquals('overlay2', $payload['storage-driver']);
    }

    public function testSharedRootlessDockerConfigDisablesContainerdSnapshotterWhenRequested(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-');

        $result = \pmssUserRootlessDockerConfigConverge('alice', $home, 0, 0, [
            'storage_driver' => 'fuse-overlayfs',
            'create_when_missing' => true,
            'disable_containerd_snapshotter' => true,
        ]);

        $this->pmssAssertArraySubsetSame(['ok' => true, 'changed' => true, 'disabled_containerd_snapshotter' => true], $result);
        $payload = $this->assertDaemonConfigContainsCgroupfs($home);
        $this->assertEquals('fuse-overlayfs', $payload['storage-driver']);
        $this->assertFalse($payload['features']['containerd-snapshotter']);
    }

    public function testSharedRootlessDockerConfigPreservesExistingFeatureKeys(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-');
        $this->writeDaemonConfig($home, ['features' => ['buildkit' => true]]);

        $result = \pmssUserRootlessDockerConfigConverge('alice', $home, 0, 0, [
            'disable_containerd_snapshotter' => true,
        ]);

        $this->assertTrue($result['ok']);
        $payload = $this->readDaemonConfig($home);
        $this->assertFalse($payload['features']['containerd-snapshotter']);
        $this->assertTrue($payload['features']['buildkit']);
    }

    public function testSharedRootlessDockerConfigRemovesUnavailablePmssDriver(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-');
        $this->writeDaemonConfig($home, ['storage-driver' => 'fuse-overlayfs']);

        $result = \pmssUserRootlessDockerConfigConverge('alice', $home, 0, 0, [
            'remove_pmss_storage_driver' => true,
            'invalid_json_as_empty' => true,
        ]);

        $this->pmssAssertArraySubsetSame(['ok' => true, 'removed_storage_driver' => true], $result);
        $payload = $this->assertDaemonConfigContainsCgroupfs($home);
        $this->assertTrue(!array_key_exists('storage-driver', $payload));
    }

    public function testSharedRootlessDockerConfigCanAbortOnInvalidJson(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-');
        @mkdir($home.'/.config/docker', 0755, true);
        file_put_contents($home.'/.config/docker/daemon.json', '{broken');

        $result = \pmssUserRootlessDockerConfigConverge('alice', $home, 0, 0, [
            'create_when_missing' => true,
        ]);

        $this->pmssAssertArraySubsetSame(['ok' => false, 'reason' => 'invalid_json'], $result);
        $this->assertEquals('{broken', file_get_contents($home.'/.config/docker/daemon.json'));
    }

    public function testSharedRootlessDockerConfigRejectsSymlinkedConfigFile(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-');
        @mkdir($home.'/.config/docker', 0755, true);
        file_put_contents($home.'/outside.json', '{"keep":true}');
        symlink($home.'/outside.json', $home.'/.config/docker/daemon.json');

        $result = \pmssUserRootlessDockerConfigConverge('alice', $home, 0, 0, [
            'create_when_missing' => true,
        ]);

        $this->pmssAssertArraySubsetSame(['ok' => false, 'reason' => 'unsafe_config_file'], $result);
        $this->assertEquals('{"keep":true}', file_get_contents($home.'/outside.json'));
    }

    public function testSharedRootlessDockerConfigRejectsDirectoryConfigFile(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-');
        @mkdir($home.'/.config/docker/daemon.json', 0755, true);

        $result = \pmssUserRootlessDockerConfigConverge('alice', $home, 0, 0, [
            'create_when_missing' => true,
        ]);

        $this->pmssAssertArraySubsetSame(['ok' => false, 'reason' => 'unsafe_config_file'], $result);
    }

    private function assertDaemonConfigContainsCgroupfs(string $home): array
    {
        $payload = $this->readDaemonConfig($home);
        $this->assertEquals(['native.cgroupdriver=cgroupfs'], $payload['exec-opts']);
        return $payload;
    }

    private function writeDaemonConfig(string $home, array $payload): void
    {
        @mkdir($home.'/.config/docker', 0755, true);
        file_put_contents($home.'/.config/docker/daemon.json', json_encode($payload));
    }

    private function readDaemonConfig(string $home): array
    {
        $payload = json_decode((string) file_get_contents($home.'/.config/docker/daemon.json'), true);
        return is_array($payload) ? $payload : [];
    }
}
