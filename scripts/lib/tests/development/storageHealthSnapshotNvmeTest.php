<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/storageHealth.php';

final class StorageHealthSnapshotNvmeTest extends TestCase
{
    public function testSnapshotNvmeReturnsNullWhenBinaryMissing(): void
    {
        $device = $this->createFakeNvmeDevice();

        $this->pmssWithEnv(['PATH' => ''], function () use ($device): void {
            $this->assertEquals(null, \pmssStorageHealthSnapshotNvme(['path' => $device], [], '2025-01-01T00:00:00+00:00'));
        });
    }

    public function testSnapshotNvmeParsesMetricsAndFlags(): void
    {
        $device = $this->createFakeNvmeDevice();
        $stubDir = $this->createNvmeStub([
            'critical_warning : 1',
            'temperature : 343 K',
            'media_errors : 3',
            'num_err_log_entries : 9',
            'percentage_used : 96',
        ]);

        $this->pmssWithPathPrefix($stubDir, function () use ($device): void {
            $entry = \pmssStorageHealthSnapshotNvme([
                'path' => $device,
                'kname' => 'nvme0n1',
                'model' => 'PMSS NVMe',
                'serial' => 'ABC123',
                'size' => '1T',
            ], [], '2025-01-01T00:00:00+00:00');

            $this->assertTrue(is_array($entry));
            $this->pmssAssertArraySubsetSame(['kind' => 'nvme', 'device' => $device, 'severity' => 'fail', 'ok' => false, 'flags' => ['nvme_critical_warning', 'hot_nvme', 'wearout_critical']], $entry);
            $this->pmssAssertArraySubsetSame(['critical_warnings' => 1, 'temperature' => 70, 'percentage_used' => 96], $entry['metrics']);
        });
    }

    public function testSnapshotNvmeTracksMetricGrowthAgainstPreviousEntry(): void
    {
        $device = $this->createFakeNvmeDevice();
        $stubDir = $this->createNvmeStub([
            'critical_warning : 0',
            'temperature : 42 C',
            'media_errors : 5',
            'num_err_log_entries : 7',
            'percentage_used : 10',
        ]);

        $this->pmssWithPathPrefix($stubDir, function () use ($device): void {
            $entry = \pmssStorageHealthSnapshotNvme(
                ['path' => $device],
                ['nvme::'.$device => ['metrics' => ['media_errors' => 2, 'num_err_log_entries' => 4]]],
                '2025-01-01T00:00:00+00:00'
            );

            $this->assertTrue(is_array($entry));
            $this->pmssAssertArraySubsetSame(['severity' => 'warn', 'flags' => ['media_errors_increase', 'err_log_increase']], $entry);
        });
    }

    private function createFakeNvmeDevice(): string
    {
        return $this->pmssMakeReadableTempPath('pmss-nvme-device-', 'dev-');
    }

    private function createNvmeStub(array $body): string
    {
        return $this->pmssMakeExecutableStub('nvme', implode("\n", array_merge(['#!/bin/sh', 'cat <<\'EOF\''], $body, ['EOF']))."\n", 'pmss-nvme-bin-');
    }
}
