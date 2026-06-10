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

        $this->assertEquals('smart', $entry['kind']);
        $this->assertEquals($disk['path'], $entry['device']);
        $this->assertEquals('device unreadable', $entry['error']);
        $this->assertEquals(['device_unreadable'], $entry['flags']);
        $this->assertEquals('warn', $entry['severity']);
        $this->assertTrue(!$entry['ok']);

        $device = $this->pmssMakeReadableTempPath('pmss-smart-readable-', 'dev-');

        $entry = [];
        $this->pmssWithEnv(['PATH' => ''], function () use ($device, &$entry): void {
            $entry = \pmssStorageHealthSnapshotSmart(['path' => $device, 'kname' => 'sdy'], [], '2025-01-01T00:00:00+00:00');
        });

        $this->assertEquals('smartctl missing', $entry['error']);
        $this->assertEquals(['smartctl_missing'], $entry['flags']);
        $this->assertEquals('warn', $entry['severity']);
        $this->assertTrue(!$entry['ok']);
    }
}
