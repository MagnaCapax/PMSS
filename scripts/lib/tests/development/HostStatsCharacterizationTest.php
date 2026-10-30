<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/systemStats.php';
require_once dirname(__DIR__, 2).'/diskIostat.php';

/** Lock the host-statistics projections before separating parsers from I/O. */
class HostStatsCharacterizationTest extends TestCase
{
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
function pmssSystemStatsIopingMs($path) { return '0.2ms'; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/diskIostat.php', 'HostStatsAssemblyFixture');
        $script .= 'echo json_encode(pmssDiskIostatParseLatestSample("Device r/s w/s rMB/s wMB/s r_await w_await %util aqu-sz\ngrp1 1 2 3 4 5 6 7 8\n", 2, 123), JSON_PRESERVE_ZERO_FRACTION);';
        $this->assertSame([
            'iopsRead' => '1', 'iopsWrite' => '2', 'throughputRead' => '3', 'throughputWrite' => '4',
            'diskAwait' => '5', 'diskServiceTime' => '6', 'diskUtil' => '7', 'avgQueueSize' => '8',
            'psiFullAvg300' => 1.25, 'psiMemFullAvg300' => 1.25, 'psiCpuFullAvg300' => 1.25,
            'iopingHomeMs' => 0.2, 'diskQuantity' => 2, 'time' => 123,
        ], $this->pmssRunInlinePhpJson($script));
    }
}
