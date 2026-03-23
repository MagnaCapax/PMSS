<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/user/userConfigStore.php';

class UserConfigStoreRuntimeStub extends \UserConfigStore
{
    /** @var int */
    private $runtimeRamMiB = 0;

    public function __construct(int $runtimeRamMiB, ?string $configDir = null)
    {
        parent::__construct($configDir);
        $this->runtimeRamMiB = $runtimeRamMiB;
    }

    public function resolveRamMiB(string $username): int
    {
        return $this->runtimeRamMiB;
    }
}

class UserConfigStoreTest extends TestCase
{
    /** @var string */
    private $tempDir = '';

    private function configDirPath(): string
    {
        return $this->tempDir.'/seedbox/config';
    }

    private function legacyUsersJsonPath(): string
    {
        return $this->tempDir.'/seedbox/runtime/users.json';
    }

    private function setUpTempDir(): void
    {
        $base = sys_get_temp_dir().'/pmss-userconfigstore-tests';
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        $this->tempDir = $base.'/store-'.bin2hex(random_bytes(4));
        @mkdir($this->tempDir, 0755, true);
    }

    private function tearDownTempDir(): void
    {
        if ($this->tempDir === '' || !is_dir($this->tempDir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $path) {
            if ($path->isDir()) {
                @rmdir($path->getPathname());
            } else {
                @unlink($path->getPathname());
            }
        }
        @rmdir($this->tempDir);
        $this->tempDir = '';
    }

    private function basePayload(array $overrides = []): array
    {
        return $overrides + [
            'ramMiB' => 128,
            'rtorrentPort' => 5000,
            'quota' => 5,
            'quotaBurst' => 6,
        ];
    }

    private function persistAndReload(string $username, array $payload): array
    {
        $store = new \UserConfigStore($this->configDirPath());
        $this->assertTrue($store->set($username, $payload));
        $reloaded = $store->get($username);
        $this->assertTrue(is_array($reloaded));
        return $reloaded;
    }

    public function testSetAndGetRoundTripPreservesUnknownKeysAndForcesTrafficLimit(): void
    {
        $this->setUpTempDir();
        try {
            $store = new \UserConfigStore($this->configDirPath());
            $payload = [
                'ramMiB'       => 512,
                'rtorrentPort' => 5000,
                'quota'        => 100,
                'quotaBurst'   => 125,
                'trafficLimit' => 999,
                'customNote'   => 'keep-me',
            ];
            $this->assertTrue($store->set('alice', $payload));

            $reloaded = $store->get('alice');
            $this->assertTrue(is_array($reloaded));
            $this->assertEquals('keep-me', $reloaded['customNote']);
            $this->assertEquals(0, $reloaded['trafficLimit']);
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testTrafficCapMbitNormalisesToInt(): void
    {
        $this->setUpTempDir();
        try {
            $store = new \UserConfigStore($this->configDirPath());
            $payload = [
                'ramMiB' => 256,
                'rtorrentPort' => 5100,
                'quota' => 50,
                'quotaBurst' => 62,
                'trafficCapMbit' => '15',
            ];
            $this->assertTrue($store->set('captest', $payload));
            $reloaded = $store->get('captest');
            $this->assertTrue(is_array($reloaded));
            $this->assertEquals(15, $reloaded['trafficCapMbit']);
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testLegacyRtorrentRamCreatesRamMiBButPreservesKey(): void
    {
        $this->setUpTempDir();
        try {
            $store = new \UserConfigStore($this->configDirPath());
            $payload = [
                'rtorrentRam'  => 256,
                'rtorrentPort' => 4100,
                'quota'        => 10,
                'quotaBurst'   => 12,
            ];
            $this->assertTrue($store->set('bob', $payload));
            $reloaded = $store->get('bob');
            $this->assertTrue(is_array($reloaded));
            $this->assertEquals(256, $reloaded['ramMiB']);
            $this->assertTrue(isset($reloaded['rtorrentRam']));
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testBillingIdDefaultsTo0AndSuspendedDefaultsFalse(): void
    {
        $this->setUpTempDir();
        try {
            $reloaded = $this->persistAndReload('carol', $this->basePayload(['rtorrentPort' => 5001]));
            $this->assertEquals(0, $reloaded['billingId']);
            $this->assertEquals(false, $reloaded['suspended']);
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testDockerEnabledDefaultsTrue(): void
    {
        $this->setUpTempDir();
        try {
            $reloaded = $this->persistAndReload('docked', $this->basePayload([
                'ramMiB' => 512,
                'rtorrentPort' => 5002,
            ]));
            $this->assertEquals(true, $reloaded['dockerEnabled']);
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testDockerEnabledDefaultsFalseForStorageProduct(): void
    {
        $this->setUpTempDir();
        try {
            $reloaded = $this->persistAndReload('dockst', $this->basePayload([
                'ramMiB' => 512,
                'rtorrentPort' => 5007,
                'productType' => 'storage-box',
            ]));
            $this->assertEquals(false, $reloaded['dockerEnabled']);
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testDockerEnabledExplicitValueOverridesStorageDefault(): void
    {
        $this->setUpTempDir();
        try {
            $reloaded = $this->persistAndReload('docksx', $this->basePayload([
                'ramMiB' => 512,
                'rtorrentPort' => 5008,
                'product' => 'Storage Box 100',
                'dockerEnabled' => true,
            ]));
            $this->assertEquals(true, $reloaded['dockerEnabled']);
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testDockerEnabledNormalisesFalse(): void
    {
        $this->setUpTempDir();
        try {
            $reloaded = $this->persistAndReload('dockoff', $this->basePayload([
                'rtorrentPort' => 5003,
                'dockerEnabled' => 0,
            ]));
            $this->assertEquals(false, $reloaded['dockerEnabled']);
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testDockerEnabledNormalisesFalseString(): void
    {
        $this->setUpTempDir();
        try {
            $reloaded = $this->persistAndReload('dockstr', $this->basePayload([
                'rtorrentPort' => 5004,
                'dockerEnabled' => 'false',
            ]));
            $this->assertEquals(false, $reloaded['dockerEnabled']);
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testDockerEnabledNormalisesTrueString(): void
    {
        $this->setUpTempDir();
        try {
            $reloaded = $this->persistAndReload('dockon', $this->basePayload([
                'ramMiB' => 512,
                'rtorrentPort' => 5005,
                'dockerEnabled' => 'true',
            ]));
            $this->assertEquals(true, $reloaded['dockerEnabled']);
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testDockerEnabledForcedOffBelowRamFloor(): void
    {
        $this->setUpTempDir();
        try {
            $reloaded = $this->persistAndReload('docklow', $this->basePayload([
                'ramMiB' => 244,
                'rtorrentPort' => 5006,
                'dockerEnabled' => true,
            ]));
            $this->assertEquals(false, $reloaded['dockerEnabled']);
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testPmssUserDockerEnabledDefaultsTrueWhenMissing(): void
    {
        $this->setUpTempDir();
        try {
            $store = new \UserConfigStore($this->configDirPath());
            $this->assertEquals(true, \pmssUserDockerEnabled('alice', $store));
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testPmssUserDockerEnabledRespectsFalse(): void
    {
        $this->setUpTempDir();
        try {
            $store = new \UserConfigStore($this->configDirPath());
            $payload = [
                'ramMiB'        => 512,
                'rtorrentPort'  => 5100,
                'quota'         => 50,
                'quotaBurst'    => 62,
                'dockerEnabled' => false,
            ];
            $this->assertTrue($store->set('dockno', $payload));
            $this->assertEquals(false, \pmssUserDockerEnabled('dockno', $store));
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testPmssUserDockerEnabledUsesRuntimeRamFloor(): void
    {
        $this->setUpTempDir();
        try {
            $store = new UserConfigStoreRuntimeStub(200, $this->configDirPath());
            $payload = [
                'ramMiB'        => 512,
                'rtorrentPort'  => 5101,
                'quota'         => 50,
                'quotaBurst'    => 62,
                'dockerEnabled' => true,
            ];
            $this->assertTrue($store->set('dockruntime', $payload));
            $this->assertEquals(false, \pmssUserDockerEnabled('dockruntime', $store));
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testPmssUserDockerEnabledAllowsRuntimeRamAtFloor(): void
    {
        $this->setUpTempDir();
        try {
            $store = new UserConfigStoreRuntimeStub(245, $this->configDirPath());
            $payload = [
                'ramMiB'        => 512,
                'rtorrentPort'  => 5102,
                'quota'         => 50,
                'quotaBurst'    => 62,
                'dockerEnabled' => true,
            ];
            $this->assertTrue($store->set('dockok', $payload));
            $this->assertEquals(true, \pmssUserDockerEnabled('dockok', $store));
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testPmssUserDockerEnabledRejectsInvalidUsername(): void
    {
        $this->setUpTempDir();
        try {
            $store = new \UserConfigStore($this->configDirPath());
            $this->assertEquals(false, \pmssUserDockerEnabled('../evil', $store));
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testUsernameNormalizationStaysConsistentAcrossStoreOperations(): void
    {
        $this->setUpTempDir();
        try {
            $store = new \UserConfigStore($this->configDirPath());
            $payload = [
                'ramMiB' => 256,
                'rtorrentPort' => 5000,
                'quota' => 10,
                'quotaBurst' => 12,
            ];

            $this->assertTrue($store->set(' Alice ', $payload));
            $this->assertTrue(is_array($store->get('ALICE')));
            $this->assertEquals(true, \pmssUserDockerEnabled(' alice ', $store));
            $this->assertTrue($store->remove(' alice '));
            $this->assertEquals(null, $store->get('alice'));
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testInvalidUsernameRejected(): void
    {
        $this->setUpTempDir();
        try {
            $store = new \UserConfigStore($this->configDirPath());
            $payload = [
                'ramMiB'       => 128,
                'rtorrentPort' => 5000,
                'quota'        => 10,
                'quotaBurst'   => 12,
            ];
            $this->assertTrue($store->set('../evil', $payload) === false);
            $this->assertTrue($store->get('../evil') === null);
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testInvalidPayloadRejected(): void
    {
        $this->setUpTempDir();
        try {
            $store = new \UserConfigStore($this->configDirPath());
            $this->assertTrue($store->set('dave', [
                'ramMiB'     => 128,
                'quota'      => 10,
                'quotaBurst' => 12,
            ]) === false);
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testGetReturnsNullForInvalidJson(): void
    {
        $this->setUpTempDir();
        try {
            $usersDir = $this->configDirPath().'/users';
            @mkdir($usersDir, 0755, true);
            @file_put_contents($usersDir.'/alice.json', '{not-json');
            $store = new \UserConfigStore($this->configDirPath());
            $this->assertTrue($store->get('alice') === null);
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testGetSkipsSymlinkFiles(): void
    {
        $this->setUpTempDir();
        try {
            $usersDir = $this->configDirPath().'/users';
            @mkdir($usersDir, 0755, true);
            $target = $this->tempDir.'/target.json';
            @file_put_contents($target, json_encode([
                'ramMiB' => 1,
                'rtorrentPort' => 1,
                'quota' => 1,
                'quotaBurst' => 1,
            ]));
            @symlink($target, $usersDir.'/alice.json');
            $store = new \UserConfigStore($this->configDirPath());
            $this->assertTrue($store->get('alice') === null, 'Symlinked user config must be ignored');
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testRemoveDeletesFile(): void
    {
        $this->setUpTempDir();
        try {
            $store = new \UserConfigStore($this->configDirPath());
            $payload = [
                'ramMiB'       => 128,
                'rtorrentPort' => 5000,
                'quota'        => 10,
                'quotaBurst'   => 12,
            ];
            $this->assertTrue($store->set('erin', $payload));
            $userFile = $this->configDirPath().'/users/erin.json';
            $this->assertTrue(is_file($userFile));
            $this->assertTrue($store->remove('erin'));
            $this->assertTrue(!file_exists($userFile));
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testLoadAllReturnsSortedByUsername(): void
    {
        $this->setUpTempDir();
        try {
            $store = new \UserConfigStore($this->configDirPath());
            $payload = [
                'ramMiB'       => 128,
                'rtorrentPort' => 5000,
                'quota'        => 10,
                'quotaBurst'   => 12,
            ];
            $this->assertTrue($store->set('bob', $payload));
            $this->assertTrue($store->set('alice', $payload));
            $all = $store->loadAll();
            $this->assertEquals(['alice', 'bob'], array_keys($all));
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testGetFallsBackToLegacyAggregateWhenCanonicalMissing(): void
    {
        $this->setUpTempDir();
        try {
            $legacyPath = $this->legacyUsersJsonPath();
            @mkdir(dirname($legacyPath), 0755, true);
            @file_put_contents($legacyPath, json_encode([
                'alice' => [
                    'ramMiB' => 256,
                    'rtorrentPort' => 5000,
                    'quota' => 10,
                    'quotaBurst' => 12,
                    'trafficLimit' => 999,
                ],
            ]));

            $store = new \UserConfigStore($this->configDirPath());
            $payload = $store->get('alice');
            $this->assertTrue(is_array($payload));
            $this->assertEquals(256, $payload['ramMiB']);
            $this->assertEquals(0, $payload['trafficLimit']);
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testGetReturnsNullWhenLegacyAggregateEntryIsNotArray(): void
    {
        $this->setUpTempDir();
        try {
            @mkdir(dirname($this->legacyUsersJsonPath()), 0755, true);
            file_put_contents(
                $this->legacyUsersJsonPath(),
                json_encode(['users' => ['legacyx' => 'invalid']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            $store = new \UserConfigStore($this->configDirPath());
            $this->assertEquals(null, $store->get('legacyx'));
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testLoadAllMergesLegacyAndCanonicalPreferringCanonical(): void
    {
        $this->setUpTempDir();
        try {
            $legacyPath = $this->legacyUsersJsonPath();
            @mkdir(dirname($legacyPath), 0755, true);
            @file_put_contents($legacyPath, json_encode([
                'alice' => [
                    'ramMiB' => 128,
                    'rtorrentPort' => 4000,
                    'quota' => 5,
                    'quotaBurst' => 6,
                ],
                'bob' => [
                    'ramMiB' => 256,
                    'rtorrentPort' => 4001,
                    'quota' => 10,
                    'quotaBurst' => 12,
                ],
            ]));

            $store = new \UserConfigStore($this->configDirPath());
            $this->assertTrue($store->set('bob', [
                'ramMiB'       => 512,
                'rtorrentPort' => 5000,
                'quota'        => 20,
                'quotaBurst'   => 25,
            ]));

            $all = $store->loadAll();
            $this->assertEquals(['alice', 'bob'], array_keys($all));
            $this->assertEquals(128, $all['alice']['ramMiB']);
            $this->assertEquals(512, $all['bob']['ramMiB'], 'Canonical per-user file should override legacy aggregate');
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testLoadAllSkipsInvalidCanonicalPayloads(): void
    {
        $this->setUpTempDir();
        try {
            $userDir = $this->configDirPath().'/users';
            @mkdir($userDir, 0755, true);
            file_put_contents($userDir.'/alice.json', '{invalid');
            file_put_contents(
                $userDir.'/bob.json',
                json_encode([
                    'ramMiB' => 256,
                    'rtorrentPort' => 5200,
                    'quota' => 20,
                    'quotaBurst' => 25,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            $store = new \UserConfigStore($this->configDirPath());
            $this->assertEquals(['bob'], array_keys($store->loadAll()));
        } finally {
            $this->tearDownTempDir();
        }
    }
}
