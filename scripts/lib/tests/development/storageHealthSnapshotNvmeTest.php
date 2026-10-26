<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/storageHealth.php';

final class StorageHealthSnapshotNvmeTest extends TestCase
{
    public function testParsedProbeCountersMustFitBeforeTemperatureConversion(): void
    {
        $cases = [
            ['0', 0], ['00042', 42], [(string) PHP_INT_MAX, PHP_INT_MAX],
            ['000'.PHP_INT_MAX, PHP_INT_MAX], [PHP_INT_MAX.'0', null],
            [substr((string) PHP_INT_MAX, 0, -1).'8', null], // One above the integer limit.
            ['000'.PHP_INT_MAX.'0', null], [str_repeat('9', 100), null],
        ];
        foreach ($cases as [$raw, $expected]) {
            foreach (['C', 'K', ''] as $unit) {
                $entry = \pmssStorageHealthParseNvmeOutput(implode("\n", [
                    'critical_warning : '.$raw, 'media_errors : '.$raw,
                    'num_err_log_entries : '.$raw, 'percentage_used : '.$raw,
                    'temperature : '.$raw.' '.$unit,
                ]), [], null, 'fixture');
                foreach (['critical_warnings', 'media_errors', 'num_err_log_entries', 'percentage_used'] as $metric) {
                    $this->assertSame($expected, $entry['metrics'][$metric], $metric.': '.$raw);
                }
                $temperature = $expected === null ? null : $expected - ($unit === 'K' ? 273 : 0);
                $this->assertSame($temperature, $entry['metrics']['temperature'], $raw.' '.$unit);
            }
        }
        // NVMe retains the first match; a later valid row cannot mask overflow.
        $entry = \pmssStorageHealthParseNvmeOutput(
            'media_errors : '.PHP_INT_MAX."0\nmedia_errors : 7\ntemperature : ".PHP_INT_MAX."0 K\ntemperature : 343 K",
            [], ['media_errors' => 2], 'fixture');
        $this->assertSame(null, $entry['metrics']['media_errors']);
        $this->assertSame(null, $entry['metrics']['temperature']);
        $this->assertSame([], $entry['flags']);
    }

    public function testLegacyProbePayloadsBeforeParserSeparation(): void
    {
        $device = $this->pmssMakeReadableTempPath('pmss-nvme-characterization-', 'dev-');
        $disk = ['path' => $device, 'kname' => 'nvme0n1', 'model' => 'Fixture', 'serial' => 'NVME', 'size' => '1T'];
        $timestamp = '2025-01-01T00:00:00+00:00';
        $unknown = array_fill_keys(['critical_warnings', 'temperature', 'media_errors', 'num_err_log_entries', 'percentage_used'], null);
        // Explicit expectations freeze first-match parsing, units, thresholds and flag order.
        $cases = [
            ['unrecognized output', [], [], [], 'ok'],
            ['temperature : 342 K', [], ['temperature' => 69], [], 'ok'],
            ['temperature : 343 K', [], ['temperature' => 70], ['hot_nvme'], 'warn'],
            ['temperature : 69 C', [], ['temperature' => 69], [], 'ok'],
            ['temperature : 70', [], ['temperature' => 70], ['hot_nvme'], 'warn'],
            ['temperature : 0 K', [], ['temperature' => -273], [], 'ok'],
            ['percentage_used : 79', [], ['percentage_used' => 79], [], 'ok'],
            ['percentage_used : 80', [], ['percentage_used' => 80], ['wearout_high'], 'warn'],
            ['percentage_used : 94', [], ['percentage_used' => 94], ['wearout_high'], 'warn'],
            ['percentage_used : 95', [], ['percentage_used' => 95], ['wearout_critical'], 'warn'],
            ["temperature : 40 C\ntemperature : 99 C\npercentage_used : 80\npercentage_used : 99", [],
                ['temperature' => 40, 'percentage_used' => 80], ['wearout_high'], 'warn'],
            ["CRITICAL_WARNING : 1\r\ntemperature : 343 k\nmedia_errors : 5\nnum_err_log_entries : 7\npercentage_used : 95",
                ['media_errors' => 4, 'num_err_log_entries' => 6],
                ['critical_warnings' => 1, 'temperature' => 70, 'media_errors' => 5, 'num_err_log_entries' => 7, 'percentage_used' => 95],
                ['nvme_critical_warning', 'hot_nvme', 'wearout_critical', 'media_errors_increase', 'err_log_increase'], 'fail'],
            ["media_errors : 5\nnum_err_log_entries : 7", ['media_errors' => '4', 'num_err_log_entries' => '6'],
                ['media_errors' => 5, 'num_err_log_entries' => 7], [], 'ok'],
            ["media_errors : 0\nnum_err_log_entries : 0", ['media_errors' => 4, 'num_err_log_entries' => 6],
                ['media_errors' => 0, 'num_err_log_entries' => 0], [], 'ok'],
        ];
        foreach ($cases as [$output, $previous, $metrics, $flags, $severity]) {
            $stubDir = $this->pmssMakeLineOutputStub('nvme', explode("\n", $output), 'pmss-nvme-characterization-bin-');
            $entry = null;
            $this->pmssWithPathPrefix($stubDir, static function () use ($disk, $previous, $timestamp, &$entry): void {
                $entry = \pmssStorageHealthSnapshotNvme($disk, ['nvme::'.$disk['path'] => ['metrics' => $previous]], $timestamp);
            });
            $this->assertSame([
                'timestamp' => $timestamp, 'kind' => 'nvme', 'device' => $device, 'kname' => 'nvme0n1',
                'model' => 'Fixture', 'serial' => 'NVME', 'rota' => 0, 'size' => '1T',
                'metrics' => array_replace($unknown, $metrics), 'flags' => $flags, 'severity' => $severity, 'ok' => $severity === 'ok',
            ], $entry, $output);
        }
    }

    public function testSnapshotNvmeReturnsNullWhenBinaryMissing(): void
    {
        $device = $this->pmssMakeReadableTempPath('pmss-nvme-device-', 'dev-');

        $this->pmssWithEnv(['PATH' => ''], function () use ($device): void {
            $this->assertEquals(null, \pmssStorageHealthSnapshotNvme(['path' => $device], [], '2025-01-01T00:00:00+00:00'));
        });
    }

    public function testFailedProbesDiscardCapturedMetrics(): void
    {
        $device = $this->pmssMakeReadableTempPath('pmss-nvme-failed-', 'dev-');
        $disk = ['path' => $device, 'kname' => 'nvme0n1'];
        $previous = ['nvme::'.$device => ['metrics' => ['media_errors' => 0]]];
        // Include lock refusal, timeout, launch failures and ordinary command failures.
        foreach ([1, 2, 75, 124, 126, 127, 255] as $rc) {
            $stubDir = $this->pmssMakeExecutableStub('nvme',
                "#!/bin/sh\nprintf '%s\\n' 'critical_warning : 0' 'temperature : 42 C' 'media_errors : 5'\nexit ".$rc."\n",
                'pmss-nvme-failed-bin-');
            // No flock binary: the stub status reaches the capture boundary directly.
            $this->pmssWithEnv(['PATH' => $stubDir], function () use ($disk, $previous, $rc): void {
                $this->assertSame(null, \pmssStorageHealthSnapshotNvme($disk, $previous, '2025-01-01T00:00:00+00:00'), 'rc='.$rc);
            });
        }
    }

    public function testSuccessfulEmptyProbeRemainsUnavailable(): void
    {
        $device = $this->pmssMakeReadableTempPath('pmss-nvme-empty-', 'dev-');
        $stubDir = $this->pmssMakeExecutableStub('nvme', "#!/bin/sh\nexit 0\n", 'pmss-nvme-empty-bin-');
        $this->pmssWithEnv(['PATH' => $stubDir], function () use ($device): void {
            $this->assertSame(null, \pmssStorageHealthSnapshotNvme(['path' => $device], [], '2025-01-01T00:00:00+00:00'));
        });
    }

    public function testSnapshotNvmeParsesMetricsAndFlags(): void
    {
        $device = $this->pmssMakeReadableTempPath('pmss-nvme-device-', 'dev-');
        $stubDir = $this->pmssMakeLineOutputStub('nvme', [
            'critical_warning : 1',
            'temperature : 343 K',
            'media_errors : 3',
            'num_err_log_entries : 9',
            'percentage_used : 96',
        ], 'pmss-nvme-bin-');

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
        $device = $this->pmssMakeReadableTempPath('pmss-nvme-device-', 'dev-');
        $stubDir = $this->pmssMakeLineOutputStub('nvme', [
            'critical_warning : 0',
            'temperature : 42 C',
            'media_errors : 5',
            'num_err_log_entries : 7',
            'percentage_used : 10',
        ], 'pmss-nvme-bin-');

        $this->pmssWithPathPrefix($stubDir, function () use ($device): void {
            foreach ([
                [['media_errors' => 2, 'num_err_log_entries' => 4], 'warn', ['media_errors_increase', 'err_log_increase']],
                [['media_errors' => 5, 'num_err_log_entries' => 4], 'ok', ['err_log_increase']],
                [['media_errors' => '2', 'num_err_log_entries' => '4'], 'ok', []],
                [['media_errors' => 6, 'num_err_log_entries' => 8], 'ok', []],
            ] as [$previous, $severity, $flags]) {
                $entry = \pmssStorageHealthSnapshotNvme(
                    ['path' => $device],
                    ['nvme::'.$device => ['metrics' => $previous]],
                    '2025-01-01T00:00:00+00:00'
                );

                $this->assertTrue(is_array($entry));
                $this->pmssAssertArraySubsetSame(['severity' => $severity, 'ok' => $severity === 'ok', 'flags' => $flags], $entry);
            }
        });
    }

}
