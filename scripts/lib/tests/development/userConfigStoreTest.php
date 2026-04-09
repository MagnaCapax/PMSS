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

    protected function setUp(): void
    {
        parent::setUp();
        $this->pmssAssignTempDirProperty('tempDir', 'store', 0755, sys_get_temp_dir().'/pmss-userconfigstore-tests');
    }

    private function configDirPath(): string
    {
        return $this->tempDir.'/seedbox/config';
    }

    private function legacyUsersJsonPath(): string
    {
        return $this->tempDir.'/seedbox/runtime/users.json';
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
    }

    public function testTrafficCapMbitNormalisesToInt(): void
    {
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
    }

    public function testLegacyRtorrentRamCreatesRamMiBButPreservesKey(): void
    {
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
    }

    public function testBillingIdDefaultsTo0AndSuspendedDefaultsFalse(): void
    {
        $reloaded = $this->persistAndReload('carol', $this->basePayload(['rtorrentPort' => 5001]));
        $this->assertEquals(0, $reloaded['billingId']);
        $this->assertEquals(false, $reloaded['suspended']);
    }

    public function testDockerEnabledDefaultsTrue(): void
    {
        $reloaded = $this->persistAndReload('docked', $this->basePayload([
            'ramMiB' => 512,
            'rtorrentPort' => 5002,
        ]));
        $this->assertEquals(true, $reloaded['dockerEnabled']);
    }

    public function testLighttpdEnabledDefaultsTrue(): void
    {
        $reloaded = $this->persistAndReload('lighton', $this->basePayload([
            'ramMiB' => 512,
            'rtorrentPort' => 5012,
        ]));
        $this->assertEquals(true, $reloaded['lighttpdEnabled']);
    }

    public function testDockerEnabledIgnoresProductMetadataWhenUnset(): void
    {
        $reloaded = $this->persistAndReload('dockst', $this->basePayload([
            'ramMiB' => 512,
            'rtorrentPort' => 5007,
            'productType' => 'storage-box',
        ]));
        $this->assertEquals(true, $reloaded['dockerEnabled']);
    }

    public function testDockerEnabledExplicitValueStillWinsWhenProductMetadataExists(): void
    {
        $reloaded = $this->persistAndReload('docksx', $this->basePayload([
            'ramMiB' => 512,
            'rtorrentPort' => 5008,
            'product' => 'Storage Box 100',
            'dockerEnabled' => true,
        ]));
        $this->assertEquals(true, $reloaded['dockerEnabled']);
    }

    public function testDockerEnabledNormalisesFalse(): void
    {
        $reloaded = $this->persistAndReload('dockoff', $this->basePayload([
            'rtorrentPort' => 5003,
            'dockerEnabled' => 0,
        ]));
        $this->assertEquals(false, $reloaded['dockerEnabled']);
    }

    public function testDockerEnabledNormalisesFalseString(): void
    {
        $reloaded = $this->persistAndReload('dockstr', $this->basePayload([
            'rtorrentPort' => 5004,
            'dockerEnabled' => 'false',
        ]));
        $this->assertEquals(false, $reloaded['dockerEnabled']);
    }

    public function testDockerEnabledNormalisesTrueString(): void
    {
        $reloaded = $this->persistAndReload('dockon', $this->basePayload([
            'ramMiB' => 512,
            'rtorrentPort' => 5005,
            'dockerEnabled' => 'true',
        ]));
        $this->assertEquals(true, $reloaded['dockerEnabled']);
    }

    public function testLighttpdEnabledNormalisesFalseString(): void
    {
        $reloaded = $this->persistAndReload('lightoff', $this->basePayload([
            'ramMiB' => 512,
            'rtorrentPort' => 5013,
            'lighttpdEnabled' => 'false',
        ]));
        $this->assertEquals(false, $reloaded['lighttpdEnabled']);
    }

    public function testDockerEnabledForcedOffBelowRamFloor(): void
    {
        $reloaded = $this->persistAndReload('docklow', $this->basePayload([
            'ramMiB' => 244,
            'rtorrentPort' => 5006,
            'dockerEnabled' => true,
        ]));
        $this->assertEquals(false, $reloaded['dockerEnabled']);
    }

    public function testPmssUserDockerEnabledDefaultsTrueWhenMissing(): void
    {
        $store = new \UserConfigStore($this->configDirPath());
        $this->assertEquals(true, \pmssUserDockerEnabled('alice', $store));
    }

    public function testPmssUserDockerEnabledRespectsFalse(): void
    {
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
    }

    public function testPmssUserDockerEnabledUsesRuntimeRamFloor(): void
    {
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
    }

    public function testPmssUserDockerEnabledAllowsRuntimeRamAtFloor(): void
    {
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
    }

    public function testPmssUserDockerEnabledRejectsInvalidUsername(): void
    {
        $store = new \UserConfigStore($this->configDirPath());
        $this->assertEquals(false, \pmssUserDockerEnabled('../evil', $store));
    }

    public function testPmssUserLighttpdEnabledDefaultsTrueWhenMissing(): void
    {
        $store = new \UserConfigStore($this->configDirPath());
        $this->assertEquals(true, \pmssUserLighttpdEnabled('alice', $store));
    }

    public function testPmssUserLighttpdEnabledRespectsFalse(): void
    {
        $store = new \UserConfigStore($this->configDirPath());
        $payload = [
            'ramMiB' => 512,
            'rtorrentPort' => 5103,
            'quota' => 50,
            'quotaBurst' => 62,
            'lighttpdEnabled' => false,
        ];
        $this->assertTrue($store->set('lightno', $payload));
        $this->assertEquals(false, \pmssUserLighttpdEnabled('lightno', $store));
    }

    public function testPmssUserLighttpdEnabledRejectsInvalidUsername(): void
    {
        $store = new \UserConfigStore($this->configDirPath());
        $this->assertEquals(false, \pmssUserLighttpdEnabled('../evil', $store));
    }

    public function testUsernameNormalizationStaysConsistentAcrossStoreOperations(): void
    {
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
    }

    public function testInvalidUsernameRejected(): void
    {
        $store = new \UserConfigStore($this->configDirPath());
        $payload = [
            'ramMiB'       => 128,
            'rtorrentPort' => 5000,
            'quota'        => 10,
            'quotaBurst'   => 12,
        ];
        $this->assertTrue($store->set('../evil', $payload) === false);
        $this->assertTrue($store->get('../evil') === null);
    }

    public function testInvalidPayloadRejected(): void
    {
        $store = new \UserConfigStore($this->configDirPath());
        $this->assertTrue($store->set('dave', [
            'ramMiB'     => 128,
            'quota'      => 10,
            'quotaBurst' => 12,
        ]) === false);
    }

    public function testGetReturnsNullForInvalidJson(): void
    {
        $usersDir = $this->configDirPath().'/users';
        @mkdir($usersDir, 0755, true);
        @file_put_contents($usersDir.'/alice.json', '{not-json');
        $store = new \UserConfigStore($this->configDirPath());
        $this->assertTrue($store->get('alice') === null);
    }

    public function testGetSkipsSymlinkFiles(): void
    {
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
    }

    public function testRemoveDeletesFile(): void
    {
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
    }

    public function testLoadAllReturnsSortedByUsername(): void
    {
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
    }

    public function testGetFallsBackToLegacyAggregateWhenCanonicalMissing(): void
    {
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
    }

    public function testGetReturnsNullWhenLegacyAggregateEntryIsNotArray(): void
    {
        @mkdir(dirname($this->legacyUsersJsonPath()), 0755, true);
        file_put_contents(
            $this->legacyUsersJsonPath(),
            json_encode(['users' => ['legacyx' => 'invalid']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $store = new \UserConfigStore($this->configDirPath());
        $this->assertEquals(null, $store->get('legacyx'));
    }

    public function testLoadAllMergesLegacyAndCanonicalPreferringCanonical(): void
    {
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
    }

    public function testGetAndLoadAllShareTheSameNormalizationAcrossStorageBackends(): void
    {
        @mkdir(dirname($this->legacyUsersJsonPath()), 0755, true);
        file_put_contents(
            $this->legacyUsersJsonPath(),
            json_encode([
                'users' => [
                    'alice' => [
                        'ramMiB' => '256',
                        'rtorrentPort' => 4000,
                        'quota' => 10,
                        'quotaBurst' => 12,
                        'trafficLimit' => 999,
                    ],
                    'legacyx' => 'invalid',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $store = new \UserConfigStore($this->configDirPath());
        $this->assertTrue($store->set('bob', [
            'ramMiB' => 512,
            'rtorrentPort' => 5000,
            'quota' => 20,
            'quotaBurst' => 25,
            'trafficLimit' => 77,
        ]));

        $all = $store->loadAll();

        $this->assertEquals(['alice', 'bob'], array_keys($all));
        $this->assertEquals($all['alice'], $store->get('alice'));
        $this->assertEquals($all['bob'], $store->get('bob'));
        $this->assertEquals(256, $all['alice']['ramMiB']);
        $this->assertEquals(0, $all['alice']['trafficLimit']);
        $this->assertEquals(null, $store->get('legacyx'));
    }

    public function testLoadAllSkipsInvalidCanonicalPayloads(): void
    {
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
    }
}
