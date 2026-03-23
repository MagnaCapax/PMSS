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
        $stubDir = $this->createNvmeStubDir(implode("\n", [
            '#!/bin/sh',
            'cat <<\'EOF\'',
            'critical_warning : 1',
            'temperature : 343 K',
            'media_errors : 3',
            'num_err_log_entries : 9',
            'percentage_used : 96',
            'EOF',
        ])."\n");

        $this->pmssWithPathPrefix($stubDir, function () use ($device): void {
            $entry = \pmssStorageHealthSnapshotNvme([
                'path' => $device,
                'kname' => 'nvme0n1',
                'model' => 'PMSS NVMe',
                'serial' => 'ABC123',
                'size' => '1T',
            ], [], '2025-01-01T00:00:00+00:00');

            $this->assertTrue(is_array($entry));
            $this->assertEquals('nvme', $entry['kind']);
            $this->assertEquals($device, $entry['device']);
            $this->assertEquals(1, $entry['metrics']['critical_warnings']);
            $this->assertEquals(70, $entry['metrics']['temperature']);
            $this->assertEquals(96, $entry['metrics']['percentage_used']);
            $this->assertEquals('fail', $entry['severity']);
            $this->assertTrue(!$entry['ok']);
            $this->assertEquals(['nvme_critical_warning', 'hot_nvme', 'wearout_critical'], $entry['flags']);
        });
    }

    public function testSnapshotNvmeTracksMetricGrowthAgainstPreviousEntry(): void
    {
        $device = $this->createFakeNvmeDevice();
        $stubDir = $this->createNvmeStubDir(implode("\n", [
            '#!/bin/sh',
            'cat <<\'EOF\'',
            'critical_warning : 0',
            'temperature : 42 C',
            'media_errors : 5',
            'num_err_log_entries : 7',
            'percentage_used : 10',
            'EOF',
        ])."\n");

        $this->pmssWithPathPrefix($stubDir, function () use ($device): void {
            $entry = \pmssStorageHealthSnapshotNvme(
                ['path' => $device],
                ['nvme::'.$device => ['metrics' => ['media_errors' => 2, 'num_err_log_entries' => 4]]],
                '2025-01-01T00:00:00+00:00'
            );

            $this->assertTrue(is_array($entry));
            $this->assertEquals('warn', $entry['severity']);
            $this->assertEquals(['media_errors_increase', 'err_log_increase'], $entry['flags']);
        });
    }

    private function createFakeNvmeDevice(): string
    {
        $dir = $this->pmssMakeTempDir('pmss-nvme-device-');
        $path = $dir.'/dev-nvme0n1';
        file_put_contents($path, 'device');
        return $path;
    }

    private function createNvmeStubDir(string $script): string
    {
        $binDir = $this->pmssMakeTempDir('pmss-nvme-bin-');
        file_put_contents($binDir.'/nvme', $script);
        @chmod($binDir.'/nvme', 0755);
        return $binDir;
    }
}
