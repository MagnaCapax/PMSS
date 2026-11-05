<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/systemStats.php';
require_once dirname(__DIR__, 2).'/diskIostat.php';

/** Lock the host-statistics projections before separating parsers from I/O. */
class HostStatsCharacterizationTest extends TestCase
{
    public function testCollectionKeepsProbeOrderMemoryDefaultsAndIopingGate(): void
    {
        $script = <<<'PHP'
namespace HostStatsCollectionFixture;
function pmssTestModeEnabled() { return true; }
function pmssReadRegularFileContents($path) {
    $GLOBALS['events'][] = $path;
    if ($path === '/proc/stat') return 'cpu 1 2 3 4 5';
    if ($path === '/proc/diskstats') return '8 0 sda 0 0 0 0 0 0 0 0 0 123';
    return '1.00 2.50 0 1/2 3';
}
function usleep($usec) { $GLOBALS['events'][] = $usec; }
function pmssProcMeminfoFieldsRead() { $GLOBALS['events'][] = 'memory'; return $GLOBALS['memory']; }
function pmssCommandPath($name) { $GLOBALS['events'][] = $name; return $GLOBALS['ioping'] ? '/fixture/ioping' : ''; }
function pmssSystemStatsIopingMs($path) { $GLOBALS['events'][] = $path; return '1.2ms'; }
function pmssSystemStatsTopMemoryProcesses() { $GLOBALS['events'][] = 'ps'; return 'cron:1K'; }
function pmssSystemStatsPsiRead($path) { $GLOBALS['events'][] = $path; return 'na'; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/systemStats.php', 'HostStatsCollectionFixture');
        $script .= <<<'PHP'
[$GLOBALS['memory'], $GLOBALS['ioping']] = json_decode(getenv('PMSS_TEST_HOST_COLLECTION'), true);
$GLOBALS['events'] = [];
echo json_encode([pmssSystemStatsCollect(), $GLOBALS['events']]);
PHP;
        foreach ([
            [[], false, ['0K', '0K', '0K', '0K', '0K', '0K']],
            [[], true, ['0K', '0K', '0K', '0K', '0K', '0K']],
            [['MemTotal' => 1023, 'MemFree' => 1024, 'Cached' => 1048575, 'Buffers' => 1048576], true, ['1023K', '1.0M', '1024.0M', '1.0G', '0K', '0K']],
            [['SwapTotal' => 2048, 'SwapFree' => 1, 'Ignored' => 9], false, ['0K', '0K', '0K', '0K', '2.0M', '1K']],
            [['MemTotal' => 0, 'Cached' => 0, 'Buffers' => 0, 'SwapFree' => 0], true, ['0K', '0K', '0K', '0K', '0K', '0K']],
        ] as [$memory, $ioping, $values]) {
            $expected = ['load' => '1.00,2.50,0', 'cpuIowait' => '0.0']
                + array_combine(['memTotal', 'memFree', 'memCache', 'memBuffers', 'swapTotal', 'swapFree'], $values)
                + ['diskBusy' => '0.0', 'iopingRoot' => $ioping ? '1.2ms' : 'na', 'iopingHome' => $ioping ? '1.2ms' : 'na',
                    'topMem' => 'cron:1K', 'psiIo' => 'na', 'psiMem' => 'na'];
            $events = array_merge(['/proc/stat', '/proc/diskstats', 50000, '/proc/stat', '/proc/diskstats', 'memory', 'ioping', '/proc/loadavg'],
                $ioping ? ['/', '/home'] : [], ['ps', '/proc/pressure/io', '/proc/pressure/memory']);
            $this->assertSame([$expected, $events], $this->pmssRunInlinePhpJson($script, [
                'PMSS_TEST_HOST_COLLECTION' => json_encode([$memory, $ioping]),
            ]));
        }
    }

    public function testObservationKeepsProcessFailuresAndThrowableFallbacks(): void
    {
        foreach ([
            [['cron 1024'], 0, '', 'cron:1.0M'],
            [[], 0, '', 'na'],
            [['bad:name 1', 'cron 0'], 0, '', 'cron:0K'],
            [['cron 1024'], 1, '', 'na'],
            [['cron 1024'], 0, 'exception', 'na'],
            [['cron 1024'], 0, 'error', 'na'],
        ] as [$rows, $status, $throw, $expected]) {
            $this->assertSame($expected, \pmssSystemStatsTopMemoryProcesses(static function (array &$output, int &$rc) use ($rows, $status, $throw): void {
                $output = $rows;
                $rc = $status;
                if ($throw === 'exception') throw new \RuntimeException('fixture');
                if ($throw === 'error') throw new \Error('fixture');
            }));
        }
    }

    public function testInjectedIostatRunnerKeepsPublicationAndExceptionBoundaries(): void
    {
        $script = <<<'PHP'
namespace HostStatsRunnerFixture;
class RuntimeException extends \RuntimeException {}
function pmssDiskIostatDiscoverDevices() { $GLOBALS['events'][] = 'discover'; return []; }
function pmssDiskIostatSampleDevices($devices) { $GLOBALS['events'][] = 'sample'; return $devices; }
function pmssDiskIostatBuildCommand($devices) { $GLOBALS['events'][] = 'command'; return 'fixture-iostat'; }
function pmssDiskIostatParseLatestSample($raw, $count) {
    $GLOBALS['events'][] = ['parse', $raw, $count];
    if ($raw === '') throw new RuntimeException('empty sample');
    return ['time' => 123];
}
function pmssPathTargetIsSafe($path, $directory, $missing) { return true; }
function date($format) { return 'fixture-time'; }
function pmssFileWriteComplete($path, $content, $flags) {
    $GLOBALS['events'][] = ['write', $path, $content, $flags];
    return $GLOBALS['writeOk'];
}
function copy($source, $target) { $GLOBALS['events'][] = ['copy', $source, $target]; return true; }
PHP;
        // Every observation and write is intercepted; no host probe or managed file is touched.
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/diskIostat.php', 'HostStatsRunnerFixture');
        $script .= <<<'PHP'
[$mode, $GLOBALS['writeOk']] = json_decode(getenv('PMSS_TEST_HOST_RUNNER'), true);
$GLOBALS['events'] = [];
ob_start();
try {
    $result = pmssDiskIostatMain(static function ($command) use ($mode) {
        $GLOBALS['events'][] = ['run', $command];
        if ($mode === 'exception') throw new RuntimeException('runner failed');
        if ($mode === 'error') throw new \Error('runner error');
        return $mode === 'empty' ? '' : 'fixture-raw';
    });
} catch (\Error $error) {
    $result = $error->getMessage();
}
$output = ob_get_clean();
echo json_encode([$result, $output, $GLOBALS['events']]);
PHP;
        $initial = ['discover', 'sample', 'command', ['run', 'fixture-iostat']];
        $parsed = array_merge($initial, [['parse', 'fixture-raw', 1]]);
        $writes = [
            ['write', '/var/run/pmss/iostat', 'a:1:{s:4:"time";i:123;}', 0],
            ['write', PMSS_DISK_IOSTAT_HISTORY_LOG, 'fixture-time || a:1:{s:4:"time";i:123;}'."\n", FILE_APPEND],
            ['write', PMSS_DISK_IOSTAT_HISTORY_RAW_LOG, "fixture-time || fixture-raw\n---\n", FILE_APPEND],
        ];
        foreach ([
            ['ok', true, 0, '', array_merge($parsed, $writes, [['copy', '/var/run/pmss/iostat', '/var/www/iostat']])],
            ['ok', false, 0, '', array_merge($parsed, [$writes[0]])],
            ['empty', true, 0, "empty sample\n", array_merge($initial, [['parse', '', 1]])],
            ['exception', true, 0, "runner failed\n", $initial],
            ['error', true, 'runner error', '', $initial],
        ] as [$mode, $writeOk, $result, $output, $events]) {
            $this->assertSame([$result, $output, $events], $this->pmssRunInlinePhpJson($script, [
                'PMSS_TEST_HOST_RUNNER' => json_encode([$mode, $writeOk]),
            ]));
        }
    }

    public function testPressureConsumersKeepTheirDistinctMissingFieldPolicies(): void
    {
        $path = $this->pmssMakeTempFile('pmss-host-psi-');
        foreach ([
            ["some avg10=1.23 avg60=4.56 avg300=7.89 total=123\nfull avg10=0.12 avg60=0.34 avg300=0.567 total=456\n", '1.2/4.6/0.1/7.9/0.3/0.6/123/456', 0.567],
            ["full avg300=12.345 total=0\n", 'na', 12.345],
            ["some avg10=0\nfull avg300=100\n", '0.0/na/na/na/na/100.0/na/na', 100.0],
            ["some avg10=0\nfull avg300=100.01\n", '0.0/na/na/na/na/na/na/na', null],
            ["some avg10=0\nfull avg300=1e2\n", '0.0/na/na/na/na/na/na/na', null],
            ["some avg10=0\nfull avg300=1.2bad\n", '0.0/na/na/na/na/na/na/na', null],
            ["some avg10=0\nfull avg300=1.25\nfull avg300=9\n", '0.0/na/na/na/na/1.3/na/na', 1.25],
            ['', 'na', null],
        ] as [$raw, $formatted, $numeric]) {
            file_put_contents($path, $raw);
            $this->assertSame($formatted, \pmssSystemStatsPsiFromRaw($raw));
            $this->assertSame($numeric, \pmssDiskIostatReadPsiFullAvg300($path));
        }
    }

    public function testPressureFieldsRequireTheirOwnRowAndCompleteNames(): void
    {
        $path = $this->pmssMakeTempFile('pmss-host-psi-boundary-');
        foreach ([
            ["some\nfull avg10=7 avg300=9\n", 'na', 9.0],
            ["some\rfull avg10=7 avg300=9\n", 'na', null],
            ["some avg10=1\nfull\nother avg300=9\n", '1.0/na/na/na/na/na/na/na', null],
            ["some avg10=1\nfull\rother avg300=9\n", '1.0/na/na/na/na/na/na/na', null],
            ["some badavg10=7\nfull avg300=9\n", 'na', 9.0],
            ["some x_avg10=7\nfull avg300=9\n", 'na', 9.0],
            ["some avg10=1\nfull notavg300=9\n", '1.0/na/na/na/na/na/na/na', null],
            ["some avg10=1\nfull total=0avg300=9\n", '1.0/na/na/na/na/na/na/na', null],
            // Valid fields after unrelated tokens still win over lookalike names.
            ["some badavg10=7 avg10=1\nfull badavg300=99 avg300=2\n", '1.0/na/na/na/na/2.0/na/na', 2.0],
            ["some\tavg10=0\nfull\ttotal=0\tavg300=100\r\n", '0.0/na/na/na/na/100.0/na/0', 100.0],
            // Repeated rows and fields retain the first matching value.
            ["some avg60=3\nsome avg10=1\nfull total=0\nfull avg300=2 avg300=9\n", '1.0/3.0/na/na/na/2.0/na/0', 2.0],
        ] as [$raw, $formatted, $numeric]) {
            file_put_contents($path, $raw);
            $this->assertSame($formatted, \pmssSystemStatsPsiFromRaw($raw));
            $this->assertSame($numeric, \pmssDiskIostatReadPsiFullAvg300($path));
        }
    }

    public function testCpuDiskAndMemoryProjectionSnapshots(): void
    {
        $this->assertSame([10, 20, 30, 40, 50, 60], \pmssSystemStatsCpuCountersFromRaw("cpu 10 20 30 40 50 60\ncpu0 1 2 3 4 5\n"));
        $this->assertSame('50.0', \pmssSystemStatsCpuIowaitPercent([10, 20, 30, 40, 50], [20, 20, 30, 40, 60]));
        $this->assertSame('0.0', \pmssSystemStatsCpuIowaitPercent([10, 20, 30, 40, 50], [1, 2, 3, 4, 5]));
        $this->assertSame('25.0', \pmssSystemStatsDiskBusyPercent(['sda' => 100], ['sda' => 600, 'sdb' => 5000], 2.0));
        $this->assertSame('100.0', \pmssSystemStatsDiskBusyPercent(['sda' => 0], ['sda' => 3000], 1.0));
        $this->assertSame('0.0', \pmssSystemStatsDiskBusyPercent(['sda' => 0], ['sda' => 3000], 0.0));
        $this->assertSame('db:1.0G,php:1.0M,cron:0K', \pmssSystemStatsTopMemoryFromPsRows(['db 1048576', 'php 1024', 'cron 0', 'bad:name 7']));
        $this->assertSame('1.00,2.50,0', \pmssSystemStatsLoadAverageFromRaw('1.00 2.50 0 1/2 3'));
    }

    public function testIostatProjectionUsesOnlyTheLatestHeaderAndFirstGroup(): void
    {
        $zero = ['iopsRead' => '0', 'iopsWrite' => '0', 'throughputRead' => '0', 'throughputWrite' => '0',
            'diskAwait' => '0', 'diskServiceTime' => '0', 'diskUtil' => '0', 'avgQueueSize' => '0'];
        foreach ([
            ["grp1 99\nDevice r/s\ngrp1 1\nDevice w/s\ngrp1 2\ngrp1 3\n", ['iopsWrite' => '2']],
            [" Device r/s w/s \r\n grp1 01 2.00 \r\n", ['iopsRead' => '01', 'iopsWrite' => '2.00']],
            ["Device r/s w/s\ngrp1 \n", []],
            ["Device r/s r/s\ngrp1 1 2\n", ['iopsRead' => '2']],
            ["Device await r_await\ngrp1 7\n", []],
            ["Device await svctm avgqu-sz\ngrp1 7 8 9\n", ['diskAwait' => '7', 'diskServiceTime' => '8', 'avgQueueSize' => '9']],
            ["Device r_await await w_await svctm aqu-sz avgqu-sz\ngrp1 1 2 3 4 5 6\n", ['diskAwait' => '1', 'diskServiceTime' => '3', 'avgQueueSize' => '5']],
        ] as [$raw, $fields]) {
            $this->assertSame(array_replace($zero, $fields), \pmssDiskIostatParseSampleFields($raw));
        }
        foreach ([
            ["grp1 1\n", 'No iostat Device header found'],
            ["Device r/s\ngrp1 1\nDevice w/s\n", 'No grp1 line after iostat header'],
            ["Device r/s\ngrp1\n", 'No grp1 line after iostat header'],
        ] as [$raw, $message]) {
            $this->assertThrowsRuntime(static function () use ($raw): void { \pmssDiskIostatParseSampleFields($raw); }, $message);
        }
    }

    public function testLegacyIostatAssemblyKeepsSerializedFieldOrderAndTypes(): void
    {
        $script = <<<'PHP'
namespace HostStatsAssemblyFixture;
function is_readable($path) { return true; }
function file_get_contents($path) { return "full avg300=1.25\n"; }
function pmssReadRegularFileContents($path) { return null; }
function pmssProcMeminfoFieldsRead($path) { return []; }
function pmssSystemStatsIopingMs($path) { return '0.2ms'; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/diskIostat/observe.php', 'HostStatsAssemblyFixture');
        $script .= 'echo json_encode(pmssDiskIostatParseLatestSample("Device r/s w/s rMB/s wMB/s r_await w_await %util aqu-sz\ngrp1 1 2 3 4 5 6 7 8\n", 2, 123), JSON_PRESERVE_ZERO_FRACTION);';
        $this->assertSame([
            'iopsRead' => '1', 'iopsWrite' => '2', 'throughputRead' => '3', 'throughputWrite' => '4',
            'diskAwait' => '5', 'diskServiceTime' => '6', 'diskUtil' => '7', 'avgQueueSize' => '8',
            'psiFullAvg300' => 1.25, 'psiMemFullAvg300' => 1.25, 'psiCpuFullAvg300' => 1.25,
            'iopingHomeMs' => 0.2, 'diskQuantity' => 2, 'time' => 123,
            'cpuIowaitPct' => null, 'cpuBusyPct' => null, 'cpuStealPct' => null, 'cpuIdlePct' => null,
            'psiSomeAvg300' => null, 'procsBlocked' => null, 'dirtyKb' => null, 'writebackKb' => null, 'mdSyncActive' => null,
        ], $this->pmssRunInlinePhpJson($script));
    }
}
