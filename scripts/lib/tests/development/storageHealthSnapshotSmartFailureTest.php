<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/storageHealth.php';

class StorageHealthSnapshotSmartFailureTest extends TestCase
{
    public function testSnapshotSmartReturnsUnreadableFailureForMissingDevice(): void
    {
        $disk = ['path' => sys_get_temp_dir().'/pmss-smart-missing-'.bin2hex(random_bytes(4)), 'kname' => 'sdx'];

        $entry = \pmssStorageHealthSnapshotSmart($disk, [], '2025-01-01T00:00:00+00:00');

        $this->assertEquals('smart', $entry['kind']);
        $this->assertEquals($disk['path'], $entry['device']);
        $this->assertEquals('device unreadable', $entry['error']);
        $this->assertEquals(['device_unreadable'], $entry['flags']);
        $this->assertEquals('warn', $entry['severity']);
        $this->assertTrue(!$entry['ok']);
    }

    public function testSnapshotSmartReturnsMissingToolFailureBeforeExecution(): void
    {
        $device = tempnam(sys_get_temp_dir(), 'pmss-smart-readable-');
        $this->assertTrue($device !== false, 'Expected a temporary device placeholder');

        $originalPath = getenv('PATH');
        putenv('PATH=');
        try {
            $entry = \pmssStorageHealthSnapshotSmart(['path' => $device, 'kname' => 'sdy'], [], '2025-01-01T00:00:00+00:00');
        } finally {
            if ($originalPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH='.$originalPath);
            }
            @unlink($device);
        }

        $this->assertEquals('smartctl missing', $entry['error']);
        $this->assertEquals(['smartctl_missing'], $entry['flags']);
        $this->assertEquals('warn', $entry['severity']);
        $this->assertTrue(!$entry['ok']);
    }
}
