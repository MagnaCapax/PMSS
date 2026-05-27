<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/delugeManagedConfig.php';

class DelugeManagedConfigTest extends TestCase
{
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
        $this->assertStringContainsAllStrings(['"download_location": "/srv/downloads"', '"max_active_downloading": 5', '"max_active_limit": 500', '"max_connections_global": 300', '"max_upload_slots_global": 15'], $updated);
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
    "max_upload_slots_global": 15
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
}
