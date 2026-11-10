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
            'scripts/util/userDocker.php' => ['required' => ["require_once __DIR__.'/../lib/user/docker/cli.php';", 'exit(userDockerMain($argv));']],
            'scripts/lib/user/docker/cli.php' => [
                'required' => ["'../rootlessDockerConfig.php'", '$safeUser = pmssLogSafeLabel($user);'],
                'ordered' => [[
                    'needles' => ['$user = $args[1];', '$info = userDockerAccountInfo($user, $accountReason);', '$uid = (int) $info[\'uid\'];', '$runtimeDir = "/run/user/{$uid}";'],
                    'missingPrefix' => 'missing userDocker CLI account guard: ',
                    'orderPrefix' => 'userDocker must validate account before runtime paths: ',
                ]],
            ],
            'scripts/lib/user/docker/command.php' => [
                'required' => ['function userDockerAccountInfo(string $user, ?string &$reason = null): ?array',
                    'pmssValidateUsername($user)', 'pmssPasswdEntryPositiveUid($info) === null', '$rc = 127;'],
                'ordered' => [[
                    'needles' => ['function userDockerRunAs(', 'bool $placeInUserSlice = false', '$target = userDockerAccountInfo($user);', '$wrapper = pmssBuildUserShellCommand($user, $cmd);'],
                    'missingPrefix' => 'missing userDockerRunAs account guard: ',
                    'orderPrefix' => 'userDockerRunAs must validate account before shell builder: ',
                ]],
            ],
            'scripts/lib/user/docker/start.php' => ['required' => [
                "pmssCgroupModeWithDefault('v1')", 'pmssUserRootlessDockerConfigConverge($user, $home, $uid, $gid',
                'userDockerEnsureCgroupfsDaemonConfig($user, $home, $uid, (int) $info[\'gid\']);',
                'userDocker: wrote ~/.config/docker/daemon.json for cgroup v2 rootless Docker',
                'userDocker: updated ~/.config/docker/daemon.json for cgroup v2 rootless Docker', 'unsafe_config_file',
            ]],
        ]);
    }
    public function testSharedRootlessDockerConfigCreatesCgroupfsOnlyConfig(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-');

        $result = \pmssUserRootlessDockerConfigConverge('fixtur01', $home, 0, 0, [
            'create_when_missing' => true,
        ]);

        $this->pmssAssertArraySubsetSame(['ok' => true, 'changed' => true, 'created' => true], $result);
        $payload = $this->assertDaemonConfigContainsCgroupfs($home);
        $this->assertTrue(!array_key_exists('storage-driver', $payload));
    }

    public function testSharedRootlessDockerConfigPreservesCustomStorageDriver(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-');
        $this->pmssWriteRelativeFile($home, '.config/docker/daemon.json', json_encode(['storage-driver' => 'overlay2']));

        $result = \pmssUserRootlessDockerConfigConverge('fixtur01', $home, 0, 0, [
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

        $result = \pmssUserRootlessDockerConfigConverge('fixtur01', $home, 0, 0, [
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
        $this->pmssWriteRelativeFile($home, '.config/docker/daemon.json', json_encode(['features' => ['buildkit' => true]]));

        $result = \pmssUserRootlessDockerConfigConverge('fixtur01', $home, 0, 0, [
            'disable_containerd_snapshotter' => true,
        ]);

        $this->assertTrue($result['ok']);
        $payload = $this->pmssReadJsonArrayFile($home.'/.config/docker/daemon.json', []);
        $this->assertFalse($payload['features']['containerd-snapshotter']);
        $this->assertTrue($payload['features']['buildkit']);
    }

    public function testSharedRootlessDockerConfigRemovesUnavailablePmssDriver(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-');
        $this->pmssWriteRelativeFile($home, '.config/docker/daemon.json', json_encode(['storage-driver' => 'fuse-overlayfs']));

        $result = \pmssUserRootlessDockerConfigConverge('fixtur01', $home, 0, 0, [
            'remove_pmss_storage_driver' => true,
            'invalid_json_as_empty' => true,
        ]);

        $this->pmssAssertArraySubsetSame(['ok' => true, 'removed_storage_driver' => true], $result);
        $payload = $this->assertDaemonConfigContainsCgroupfs($home);
        $this->assertTrue(!array_key_exists('storage-driver', $payload));
    }

    public function testPopulatedContainerdStorePreservesDaemonBackendConfig(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-');
        $this->pmssWriteFile($home.'/.local/share/docker/containerd/metadata.db', 'data');
        $this->pmssWriteRelativeFile($home, '.config/docker/daemon.json', json_encode(['exec-opts' => ['native.cgroupdriver=cgroupfs']]));
        $configFile = $home.'/.config/docker/daemon.json';
        $before = file_get_contents($configFile);

        $this->assertTrue(\pmssUserRootlessDockerStoreExists(\pmssUserRootlessDockerDataRoot($home, $this->pmssReadJsonArrayFile($home.'/.config/docker/daemon.json', []))));
        $result = \pmssUserRootlessDockerConfigConverge('fixtur01', $home, 0, 0, [
            'storage_driver' => null,
            'remove_pmss_storage_driver' => false,
            'disable_containerd_snapshotter' => false,
            'create_when_missing' => false,
        ]);

        $this->pmssAssertArraySubsetSame(['ok' => true, 'changed' => false], $result);
        $this->assertSame($before, file_get_contents($configFile));
    }

    public function testPopulatedGraphStoreKeepsUnavailableFuseDriver(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-');
        $this->pmssWriteFile($home.'/.local/share/docker/overlay2/layer', 'data');
        $this->pmssWriteRelativeFile($home, '.config/docker/daemon.json', json_encode(['storage-driver' => 'fuse-overlayfs']));

        $this->assertTrue(\pmssUserRootlessDockerStoreExists(\pmssUserRootlessDockerDataRoot($home, $this->pmssReadJsonArrayFile($home.'/.config/docker/daemon.json', []))));
        $result = \pmssUserRootlessDockerConfigConverge('fixtur01', $home, 0, 0, [
            'storage_driver' => null,
            'remove_pmss_storage_driver' => false,
            'disable_containerd_snapshotter' => false,
            'create_when_missing' => false,
        ]);

        $this->pmssAssertArraySubsetSame(['ok' => true, 'removed_storage_driver' => false], $result);
        $this->assertSame('fuse-overlayfs', $this->pmssReadJsonArrayFile($home.'/.config/docker/daemon.json', [])['storage-driver']);
    }

    public function testSymlinkedDataRootCountsAsExistingStore(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-');
        symlink($home.'/missing-target', $home.'/linked-store');

        $this->assertTrue(\pmssUserRootlessDockerStoreExists($home.'/linked-store'));
        $this->assertTrue(\pmssUserRootlessDockerStoreExists($home.'/linked-store/'));
    }

    public function testSymlinkedStoreEntryCountsAsExistingStore(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-');
        $store = $home.'/store';
        mkdir($store);
        symlink($home.'/missing-target', $store.'/containerd');

        $this->assertTrue(\pmssUserRootlessDockerStoreExists($store));
    }

    public function testFailedStoreScanPreservesExistingStoragePolicy(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-scan-failure-');
        $store = $home.'/store';
        mkdir($store.'/containerd', 0755, true);

        // Inject a scan failure without relying on runner permissions.
        $script = <<<'PHP'
namespace RootlessDockerStoreScanFixture;
function pmssDirectoryEntriesRead(string $path) {
    if ($path === getenv('PMSS_TEST_DOCKER_STORE').'/containerd') return false;
    return \pmssDirectoryEntriesRead($path);
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/rootlessDockerConfig.php', 'RootlessDockerStoreScanFixture', ['rootlessDockerStore.php']);
        $script .= <<<'PHP'
echo json_encode(['exists' => pmssUserRootlessDockerStoreExists(getenv('PMSS_TEST_DOCKER_STORE'))]);
PHP;
        $result = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_DOCKER_STORE' => $store]);
        $this->assertTrue($result['exists']);
    }

    public function testConfiguredDataRootOutsideHomeIsNotScanned(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-');
        $outside = $this->pmssMakeTempDir('pmss-other-store-');
        symlink($outside, $home.'/linked-store');

        foreach ([$outside, $home.'/linked-store', $outside.'/missing-store'] as $configured) {
            $dataRoot = \pmssUserRootlessDockerDataRoot($home, ['data-root' => $configured]);
            $this->assertSame('', $dataRoot);
            $this->assertTrue($dataRoot === '' || \pmssUserRootlessDockerStoreExists($dataRoot));
        }
    }

    public function testEmptyAndMissingDataRootsUseInitialDriverPolicy(): void
    {
        foreach (['missing', 'empty'] as $state) {
            $home = $this->pmssMakeTempDir('pmss-rootless-docker-');
            $dataRoot = \pmssUserRootlessDockerDataRoot($home, []);
            if ($state === 'empty') {
                @mkdir($dataRoot.'/containerd', 0755, true);
            }
            $this->assertFalse(\pmssUserRootlessDockerStoreExists($dataRoot));
            $result = \pmssUserRootlessDockerConfigConverge('fixtur01', $home, 0, 0, [
                'storage_driver' => 'fuse-overlayfs',
                'create_when_missing' => true,
                'disable_containerd_snapshotter' => true,
            ]);
            $this->pmssAssertArraySubsetSame(['ok' => true, 'configured_storage_driver' => true], $result);
            $this->assertSame('fuse-overlayfs', $this->pmssReadJsonArrayFile($home.'/.config/docker/daemon.json', [])['storage-driver']);
        }
    }

    public function testDataRootResolvesFromDaemonConfig(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-');
        $custom = $home.'/custom-store';
        $this->pmssWriteFile($custom.'/image/index', 'data');
        $this->pmssWriteRelativeFile($home, '.config/docker/daemon.json', json_encode(['data-root' => $custom]));

        $resolved = \pmssUserRootlessDockerDataRoot($home, $this->pmssReadJsonArrayFile($home.'/.config/docker/daemon.json', []));
        $this->assertSame($custom, $resolved);
        $this->assertTrue(\pmssUserRootlessDockerStoreExists($resolved));
        foreach (['', 'relative/path', 42] as $invalid) {
            $this->assertSame($home.'/.local/share/docker', \pmssUserRootlessDockerDataRoot($home, ['data-root' => $invalid]));
        }
    }

    public function testSharedRootlessDockerConfigCanAbortOnInvalidJson(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-');
        $this->pmssWriteFile($home.'/.config/docker/daemon.json', '{broken');

        $result = \pmssUserRootlessDockerConfigConverge('fixtur01', $home, 0, 0, [
            'create_when_missing' => true,
        ]);

        $this->pmssAssertArraySubsetSame(['ok' => false, 'reason' => 'invalid_json'], $result);
        $this->assertEquals('{broken', file_get_contents($home.'/.config/docker/daemon.json'));
    }

    public function testUnreadableExistingConfigIsPreservedEvenWithInvalidJsonRecovery(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-read-failure-');
        $path = $home.'/.config/docker/daemon.json';
        $this->pmssWriteFile($path, '{"keep":true}');

        // Inject a read failure independently of the runner's privileges.
        $script = <<<'PHP'
namespace RootlessDockerConfigReadFixture;
function file_get_contents($path) {
    if ($path === getenv('PMSS_TEST_DOCKER_CONFIG_PATH')) return false;
    return \file_get_contents($path);
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/rootlessDockerConfig.php', 'RootlessDockerConfigReadFixture');
        $script .= <<<'PHP'
$path = getenv('PMSS_TEST_DOCKER_CONFIG_PATH');
$result = pmssUserRootlessDockerConfigConverge('fixtur01', dirname(dirname(dirname($path))), 0, 0, [
    'create_when_missing' => true, 'invalid_json_as_empty' => true,
]);
echo json_encode(['result' => $result, 'bytes' => \file_get_contents($path),
    'temporaryFiles' => glob(dirname($path).'/.daemon.json.*')]);
PHP;
        $result = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_DOCKER_CONFIG_PATH' => $path]);

        $this->pmssAssertArraySubsetSame(['ok' => false, 'changed' => false, 'reason' => 'read_failed'], $result['result']);
        $this->assertSame('{"keep":true}', $result['bytes']);
        $this->assertSame([], $result['temporaryFiles']);
    }

    public function testSharedRootlessDockerConfigRejectsSymlinkedConfigFile(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-');
        @mkdir($home.'/.config/docker', 0755, true);
        $this->pmssWriteFile($home.'/outside.json', '{"keep":true}');
        symlink($home.'/outside.json', $home.'/.config/docker/daemon.json');

        $result = \pmssUserRootlessDockerConfigConverge('fixtur01', $home, 0, 0, [
            'create_when_missing' => true,
        ]);

        $this->pmssAssertArraySubsetSame(['ok' => false, 'reason' => 'unsafe_config_file'], $result);
        $this->assertEquals('{"keep":true}', file_get_contents($home.'/outside.json'));
    }

    public function testSharedRootlessDockerConfigRejectsDirectoryConfigFile(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-');
        @mkdir($home.'/.config/docker/daemon.json', 0755, true);

        $result = \pmssUserRootlessDockerConfigConverge('fixtur01', $home, 0, 0, [
            'create_when_missing' => true,
        ]);

        $this->pmssAssertArraySubsetSame(['ok' => false, 'reason' => 'unsafe_config_file'], $result);
    }

    public function testRootlessDockerConfigWriteRejectsNulPath(): void
    {
        $reason = null;
        $this->assertFalse(\pmssUserRootlessDockerConfigWrite("/tmp/daemon\0.json", '{}', 0, 0, $reason));
        $this->assertSame('unsafe_config_file', $reason);
    }

    public function testRootlessDockerConfigWriteRunsAsAccountWhenRoot(): void
    {
        $script = <<<'PHP'
namespace RootlessDockerAccountWriteFixture;
function posix_geteuid() { return 0; }
function posix_getpwuid($uid) { return ['gid' => 1002]; }
function pmssAccountHomeIdentity($path) { return $GLOBALS['identity']; }
function pmssReplaceAccountFile(...$args) { $GLOBALS['calls'][] = $args; return $GLOBALS['written']; }
function pmssReplaceUserFile(...$args) { throw new \RuntimeException('Root must use the account writer'); }
function pmssLockFilePathIsSafe($path) { return true; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/rootlessDockerConfig.php', 'RootlessDockerAccountWriteFixture');
        $script .= <<<'PHP'
$results = [];
foreach ([
    [1001, 1002, ['/srv/pmss-test-home', 'fixtur01', 1001], true],
    [1001, 1002, null, true],
    [1003, 1002, ['/srv/pmss-test-home', 'fixtur01', 1001], true],
    [0, 1002, ['/home/root', 'root', 0], true],
    [1001, 1003, ['/srv/pmss-test-home', 'fixtur01', 1001], true],
    [1001, 1002, ['/srv/pmss-test-home', 'fixtur01', 1001], false],
] as [$uid, $gid, $identity, $written]) {
    $GLOBALS['identity'] = $identity;
    $GLOBALS['written'] = $written;
    $GLOBALS['calls'] = [];
    $reason = 'old';
    $ok = pmssUserRootlessDockerConfigWrite('/srv/pmss-test-home/.config/docker/daemon.json', '{}', $uid, $gid, $reason);
    $results[] = [$ok, $reason, $GLOBALS['calls']];
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $expectedCall = ['fixtur01', '/srv/pmss-test-home', '/srv/pmss-test-home/.config/docker/daemon.json', '{}', 0600, true];
        $this->assertSame([true, null, [$expectedCall]], $results[0]);
        foreach ([1, 2, 3, 4] as $index) {
            $this->assertSame([false, 'write_failed', []], $results[$index]);
        }
        $this->assertSame([false, 'write_failed', [$expectedCall]], $results[5]);
    }

    public function testRootlessDockerConfigFailedPublicationPreservesExistingFile(): void
    {
        $root = $this->pmssMakeTempDir('pmss-rootless-docker-short-write-');
        $script = <<<'PHP'
namespace RootlessDockerConfigWriteFixture;
PHP;
        $script .= $this->pmssInlinePhpAtomicPublicationShims('temp');
        $script .= \pmssTestingFunctionExport('pmssFileOpenWithUmask', 'RootlessDockerConfigWriteFixture');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/lighttpd/userFileWrite.php', 'RootlessDockerConfigWriteFixture', \PMSS_USER_FILE_MODULES);
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/rootlessDockerConfig.php', 'RootlessDockerConfigWriteFixture');
        $script .= <<<'PHP'
$GLOBALS['mode'] = getenv('PMSS_TEST_WRITE_FAILURE');
$GLOBALS['tempCalls'] = 0;
$path = getenv('PMSS_TEST_DOCKER_CONFIG_ROOT').'/daemon.json';
\file_put_contents($path, '{"keep":true}');
$reason = null;
$result = pmssUserRootlessDockerConfigWrite($path, '{"exec-opts":[]}', 0, 0, $reason);
echo json_encode(['result' => $result, 'reason' => $reason, 'bytes' => \file_get_contents($path),
    'temporaryFiles' => glob($path.'.pmss-tmp-*')]);
PHP;
        foreach (['short', 'zero', 'false', 'temp', 'chmod', 'rename'] as $mode) {
            $result = $this->pmssRunInlinePhpJson($script, [
                'PMSS_TEST_DOCKER_CONFIG_ROOT' => $root, 'PMSS_TEST_WRITE_FAILURE' => $mode,
            ]);
            $this->assertFalse($result['result'], $mode);
            $this->assertSame('write_failed', $result['reason'], $mode);
            $this->assertSame('{"keep":true}', $result['bytes'], $mode);
            $this->assertSame([], $result['temporaryFiles'], $mode);
        }
    }

    private function assertDaemonConfigContainsCgroupfs(string $home): array
    {
        $payload = $this->pmssReadJsonArrayFile($home.'/.config/docker/daemon.json', []);
        $this->assertEquals(['native.cgroupdriver=cgroupfs'], $payload['exec-opts']);
        return $payload;
    }
}
