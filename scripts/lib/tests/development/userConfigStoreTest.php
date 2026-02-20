<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/user/userConfigStore.php';

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
            $store = new \UserConfigStore($this->configDirPath());
            $payload = [
                'ramMiB'       => 128,
                'rtorrentPort' => 5001,
                'quota'        => 5,
                'quotaBurst'   => 6,
            ];
            $this->assertTrue($store->set('carol', $payload));
            $reloaded = $store->get('carol');
            $this->assertTrue(is_array($reloaded));
            $this->assertEquals(0, $reloaded['billingId']);
            $this->assertEquals(false, $reloaded['suspended']);
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
}
