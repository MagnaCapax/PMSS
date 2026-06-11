<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/storageHealth.php';

class StorageHealthSnapshotSmartFailureTest extends TestCase
{
    public function testSnapshotSmartGuardFailures(): void
    {
        $disk = ['path' => sys_get_temp_dir().'/pmss-smart-missing-'.bin2hex(random_bytes(4)), 'kname' => 'sdx'];

        $entry = \pmssStorageHealthSnapshotSmart($disk, [], '2025-01-01T00:00:00+00:00');

        $this->assertSmartGuardEntry($entry, $disk['path'], 'device unreadable', 'device_unreadable');

        $device = $this->pmssMakeReadableTempPath('pmss-smart-readable-', 'dev-');

        $entry = [];
        $this->pmssWithEnv(['PATH' => ''], function () use ($device, &$entry): void {
            $entry = \pmssStorageHealthSnapshotSmart(['path' => $device, 'kname' => 'sdy'], [], '2025-01-01T00:00:00+00:00');
        });

        $this->assertSmartGuardEntry($entry, $device, 'smartctl missing', 'smartctl_missing');
    }

    private function assertSmartGuardEntry(array $entry, string $device, string $error, string $flag): void
    {
        $this->pmssAssertArraySubsetSame(['kind' => 'smart', 'device' => $device, 'error' => $error, 'flags' => [$flag], 'severity' => 'warn', 'ok' => false], $entry);
    }
}
