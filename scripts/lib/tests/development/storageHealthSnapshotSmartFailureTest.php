<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/storageHealth.php';

class StorageHealthSnapshotSmartFailureTest extends TestCase
{
    public function testMalformedPersistedMetricsUseAbsentHistoryResult(): void
    {
        $device = $this->pmssMakeReadableTempPath('pmss-smart-history-', 'dev-');
        $stub = $this->pmssMakeLineOutputStub('smartctl', [
            'SMART Health Status: PASSED',
            '199 UDMA_CRC_Error_Count 4',
            '197 Current_Pending_Sector 1',
        ], 'pmss-smart-history-bin-');
        $this->pmssWithEnv(['PATH' => $stub], function () use ($device): void {
            $disk = ['path' => $device];
            $timestamp = '2025-01-01T00:00:00+00:00';
            $expected = \pmssStorageHealthSnapshotSmart($disk, [], $timestamp);
            foreach ([null, false, true, 0, 42, 12.5, '', 'invalid', []] as $metrics) {
                $this->assertSame($expected, \pmssStorageHealthSnapshotSmart(
                    $disk, ['smart::'.$device => ['metrics' => $metrics]], $timestamp
                ));
            }
            // Real history must still contribute growth findings.
            $entry = \pmssStorageHealthSnapshotSmart($disk, [
                'smart::'.$device => ['metrics' => ['udma_crc' => 2, 'pending' => 0]],
            ], $timestamp);
            $this->assertSame(['pending_sectors', 'pending_increase', 'link_errors_increase'], $entry['flags']);
        });
    }

    public function testHistoryKeysRejectContainersAndPreserveLegacyDefaults(): void
    {
        $path = $this->pmssMakeTempFile('pmss-smart-history-jsonl-');
        $entries = [
            ['kind' => 'smart', 'device' => '/dev/sda', 'metrics' => ['pending' => 0]],
            ['kind' => 'raid', 'array' => 'md0'],
            ['kind' => 'Array', 'device' => 'global'],
            ['kind' => 'smart', 'device' => 'Array'],
            [],
            ['kind' => null, 'device' => null, 'array' => 'md1'],
            ['kind' => 7, 'device' => false],
            ['kind' => 'nvme', 'device' => '/dev/nvme0n1', 'array' => []],
            ['kind' => 'smart', 'device' => '/dev/sda', 'metrics' => ['pending' => 1]],
        ];
        // Rejected records must not overwrite literal "Array" keys or emit warnings.
        $invalid = [
            ['kind' => [], 'device' => 'global'],
            ['kind' => (object) ['bad' => 'kind'], 'device' => 'global'],
            ['kind' => 'smart', 'device' => []],
            ['kind' => 'smart', 'device' => (object) ['bad' => 'device']],
            ['kind' => 'raid', 'array' => []],
            ['kind' => 'raid', 'device' => null, 'array' => (object) ['bad' => 'array']],
        ];
        $this->pmssAppendFixtureLines($path, array_merge($entries, $invalid));
        $this->assertSame([
            'smart::/dev/sda' => $entries[8], 'raid::md0' => $entries[1],
            'Array::global' => $entries[2], 'smart::Array' => $entries[3],
            '::global' => $entries[4], '::md1' => $entries[5], '7::' => $entries[6],
            'nvme::/dev/nvme0n1' => $entries[7],
        ], \pmssStorageHealthReadLastEntries($path));
    }

    public function testTimeoutFindingsPreserveFailedAndStandbyHealth(): void
    {
        $device = $this->pmssMakeReadableTempPath('pmss-smart-timeout-', 'dev-');
        foreach ([
            ['SMART Health Status: PASSED', 'warn', ['smartctl_timeout']],
            ['SMART Health Status: FAILED', 'fail', ['health_not_ok', 'smartctl_timeout']],
            ['Device is in STANDBY', 'warn', ['standby', 'smartctl_timeout']],
            ['', 'warn', ['smartctl_empty']],
        ] as [$output, $severity, $flags]) {
            $stub = $this->pmssMakeExecutableStub('smartctl', "#!/bin/sh\nprintf '%s' ".escapeshellarg($output)."\nexit 124\n", 'pmss-smart-timeout-bin-');
            $this->pmssWithEnv(['PATH' => $stub], function () use ($device, $severity, $flags): void {
                $entry = \pmssStorageHealthSnapshotSmart(['path' => $device], [], '2025-01-01T00:00:00+00:00');
                $this->pmssAssertArraySubsetSame(['severity' => $severity, 'ok' => false, 'flags' => $flags], $entry);
            });
        }
    }

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
