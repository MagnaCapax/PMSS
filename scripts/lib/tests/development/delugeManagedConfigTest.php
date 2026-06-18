<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/delugeManagedConfig.php';

class DelugeManagedConfigTest extends TestCase
{
    private function pmssDelugeHostlistFixture(string $token, string $host = '127.0.0.1'): string
    {
        return json_encode(['file' => 1, 'format' => 1], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            .json_encode(
                [
                    'hosts' => [
                        ['ThisFieldNeedsDocumentation', $host, 58846, 'localclient', $token],
                    ],
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            );
    }

    private function pmssWriteConvergedCoreConfig(string $homeRoot, string $username): void
    {
        $this->pmssWriteRelativeFile($homeRoot, $username.'/.config/deluge/core.conf', <<<'JSON'
{
    "file": 1,
    "format": 1
}{
    "download_location": "/home/alice/data",
    "max_active_downloading": 5,
    "max_active_limit": 500,
    "max_connections_global": 300,
    "max_upload_slots_global": 15,
    "pre_allocate_storage": true
}
JSON
        );
    }

    public function testApplyManagedConfigRestoresFleetSafetyLimits(): void
    {
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-deluge-managed-');
        $configPath = $this->pmssWriteRelativeFile($homeRoot, 'alice/.config/deluge/core.conf', <<<'JSON'
{
    "file": 1,
    "format": 1
}{
    "download_location": "/srv/downloads",
    "max_active_downloading": 33,
    "max_active_limit": 999,
    "max_connections_global": 999,
    "max_upload_slots_global": 60
}
JSON
        );
        chmod($configPath, 0640);

        $this->assertTrue(\pmssDelugeApplyManagedConfig('alice'));

        $updated = (string) file_get_contents($configPath);
        $this->assertStringContainsAllStrings(['"download_location": "/srv/downloads"', '"max_active_downloading": 5', '"max_active_limit": 500', '"max_connections_global": 300', '"max_upload_slots_global": 15', '"pre_allocate_storage": true'], $updated);
        $this->assertSame(0640, fileperms($configPath) & 0777);
    }

    public function testApplyManagedConfigPreservesUserOwnedSettings(): void
    {
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-deluge-managed-preserve-');
        $configPath = $this->pmssWriteRelativeFile($homeRoot, 'alice/.config/deluge/core.conf', <<<'JSON'
{
    "file": 1,
    "format": 1
}{
    "download_location": "/home/alice/data",
    "enabled_plugins": [
        "Label"
    ],
    "max_active_downloading": 1,
    "max_connections_global": 1,
    "max_upload_speed": 250.0
}
JSON
        );

        $this->assertTrue(\pmssDelugeApplyManagedConfig('alice'));

        $updated = (string) file_get_contents($configPath);
        $this->assertStringContainsAllStrings(['"download_location": "/home/alice/data"', '"enabled_plugins": [', '"Label"', '"max_upload_speed": 250', '"max_active_limit": 500', '"max_upload_slots_global": 15'], $updated);
    }

    public function testApplyManagedConfigReturnsFalseWhenAlreadyConverged(): void
    {
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-deluge-managed-nochange-');
        $configPath = $this->pmssWriteRelativeFile($homeRoot, 'alice/.config/deluge/core.conf', <<<'JSON'
{
    "file": 1,
    "format": 1
}{
    "download_location": "/home/alice/data",
    "max_active_downloading": 5,
    "max_active_limit": 500,
    "max_connections_global": 300,
    "max_upload_slots_global": 15,
    "pre_allocate_storage": true
}
JSON
        );
        $original = (string) file_get_contents($configPath);

        $this->assertFalse(\pmssDelugeApplyManagedConfig('alice'));
        $this->assertSame($original, (string) file_get_contents($configPath));
    }

    public function testApplyManagedConfigRejectsSymlinkTarget(): void
    {
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-deluge-managed-symlink-');
        $configDir = $homeRoot.'/alice/.config/deluge';
        @mkdir($configDir, 0755, true);
        @symlink($homeRoot.'/missing-target', $configDir.'/core.conf');

        $this->assertFalse(\pmssDelugeApplyManagedConfig('alice'));
    }

    public function testApplyManagedConfigSynchronizesHostlistPasswordFromAuth(): void
    {
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-deluge-hostlist-sync-');
        $this->pmssWriteConvergedCoreConfig($homeRoot, 'alice');
        $this->pmssWriteRelativeFile($homeRoot, 'alice/.config/deluge/auth', "localclient:new-secret:10\n");
        $this->pmssWriteRelativeFile($homeRoot, 'alice/.config/deluge/hostlist.conf', $this->pmssDelugeHostlistFixture('old-secret'));

        $this->assertTrue(\pmssDelugeApplyManagedConfig('alice'));

        $updated = (string) file_get_contents($homeRoot.'/alice/.config/deluge/hostlist.conf');
        $this->assertStringContainsString('"new-secret"', $updated);
        $this->assertStringNotContainsString('"old-secret"', $updated);
    }

    public function testHostlistSyncPreservesMode(): void
    {
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-deluge-hostlist-mode-');
        $authPath = $this->pmssWriteRelativeFile($homeRoot, 'alice/.config/deluge/auth', "localclient:fresh-token:10\n");
        $hostlistPath = $this->pmssWriteRelativeFile($homeRoot, 'alice/.config/deluge/hostlist.conf', $this->pmssDelugeHostlistFixture('stale-token'));
        chmod($hostlistPath, 0640);

        $this->assertTrue(\pmssDelugeHostlistSyncLocalclientPassword('alice', $hostlistPath, $authPath));
        $this->assertSame(0640, fileperms($hostlistPath) & 0777);
    }

    public function testHostlistSyncAcceptsLocalhostEntry(): void
    {
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-deluge-hostlist-localhost-');
        $authPath = $this->pmssWriteRelativeFile($homeRoot, 'alice/.config/deluge/auth', "localclient:fresh-token:10\n");
        $hostlistPath = $this->pmssWriteRelativeFile($homeRoot, 'alice/.config/deluge/hostlist.conf', $this->pmssDelugeHostlistFixture('stale-token', 'localhost'));

        $this->assertTrue(\pmssDelugeHostlistSyncLocalclientPassword('alice', $hostlistPath, $authPath));
        $this->assertStringContainsString('"fresh-token"', (string) file_get_contents($hostlistPath));
    }

    public function testHostlistSyncReturnsFalseWhenAlreadyConverged(): void
    {
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-deluge-hostlist-nochange-');
        $authPath = $this->pmssWriteRelativeFile($homeRoot, 'alice/.config/deluge/auth', "localclient:same-token:10\n");
        $hostlistPath = $this->pmssWriteRelativeFile($homeRoot, 'alice/.config/deluge/hostlist.conf', $this->pmssDelugeHostlistFixture('same-token'));
        $original = (string) file_get_contents($hostlistPath);

        $this->assertFalse(\pmssDelugeHostlistSyncLocalclientPassword('alice', $hostlistPath, $authPath));
        $this->assertSame($original, (string) file_get_contents($hostlistPath));
    }

    public function testHostlistSyncReturnsFalseWhenAuthHasNoLocalclient(): void
    {
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-deluge-hostlist-noauth-');
        $authPath = $this->pmssWriteRelativeFile($homeRoot, 'alice/.config/deluge/auth', "other:token:5\n");
        $hostlistPath = $this->pmssWriteRelativeFile($homeRoot, 'alice/.config/deluge/hostlist.conf', $this->pmssDelugeHostlistFixture('old-secret'));

        $this->assertFalse(\pmssDelugeHostlistSyncLocalclientPassword('alice', $hostlistPath, $authPath));
        $this->assertStringContainsString('"old-secret"', (string) file_get_contents($hostlistPath));
    }

    public function testHostlistSyncRejectsSymlinkTarget(): void
    {
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-deluge-hostlist-link-');
        $configDir = $homeRoot.'/alice/.config/deluge';
        @mkdir($configDir, 0755, true);
        $authPath = $this->pmssWriteRelativeFile($homeRoot, 'alice/.config/deluge/auth', "localclient:new-secret:10\n");
        $targetPath = $configDir.'/hostlist-real.conf';
        $linkPath = $configDir.'/hostlist.conf';
        file_put_contents($targetPath, $this->pmssDelugeHostlistFixture('old-secret'));
        if (!function_exists('symlink') || @symlink($targetPath, $linkPath) === false) {
            throw new SkipTest('symlink not supported in this environment');
        }

        $this->assertFalse(\pmssDelugeHostlistSyncLocalclientPassword('alice', $linkPath, $authPath));
        $this->assertStringContainsString('"old-secret"', (string) file_get_contents($targetPath));
    }
}
