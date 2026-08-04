<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/storageHealth.php';

class StorageHealthSnapshotSmartFailureTest extends TestCase
{
    public function testSnapshotSmartGuardFailures(): void
    {
        $disk = ['path' => sys_get_temp_dir().'/pmss-smart-missing-'.bin2hex(random_bytes(4)), 'kname' => 'sdx'];

        $this->assertSmartGuardEntry(\pmssStorageHealthSnapshotSmart($disk, [], '2025-01-01T00:00:00+00:00'), $disk['path'], 'device unreadable', 'device_unreadable');

        $device = $this->pmssMakeReadableTempPath('pmss-smart-readable-', 'dev-');

        $this->pmssWithEnv(['PATH' => ''], function () use ($device): void {
            $this->assertSmartGuardEntry(\pmssStorageHealthSnapshotSmart(['path' => $device, 'kname' => 'sdy'], [], '2025-01-01T00:00:00+00:00'), $device, 'smartctl missing', 'smartctl_missing');
        });
    }

    public function testSnapshotSmartReportsBusyProbeSeparately(): void
    {
        $device = $this->pmssMakeReadableTempPath('pmss-smart-locked-', 'dev-');
        $flockDir = $this->pmssMakeExecutableStub('flock', "#!/bin/sh\nexit 75\n", 'pmss-smart-flock-');
        $smartctlDir = $this->pmssMakeLineOutputStub('smartctl', ['SMART overall-health self-assessment test result: PASSED'], 'pmss-smart-locked-bin-');

        $this->pmssWithEnv(['PATH' => $flockDir.':'.$smartctlDir], function () use ($device): void {
            $entry = \pmssStorageHealthSnapshotSmart(['path' => $device, 'kname' => 'sdz'], [], '2025-01-01T00:00:00+00:00');
            $this->pmssAssertArraySubsetSame(['error' => 'smartctl probe already running', 'flags' => ['smartctl_probe_locked'], 'severity' => 'warn', 'ok' => false], $entry);
        });
    }

    private function assertSmartGuardEntry(array $entry, string $device, string $error, string $flag): void
    {
        $this->pmssAssertArraySubsetSame(['kind' => 'smart', 'device' => $device, 'error' => $error, 'flags' => [$flag], 'severity' => 'warn', 'ok' => false], $entry);
    }
}
