<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/diskIostat.php';

/** Host contention fixtures never run iostat, ioping or read the host's proc tree. */
class DiskIostatContentionTest extends TestCase
{
    public function testCpuUsesLastReportAndHeaderNames(): void
    {
        $header = "avg-cpu: %user %nice %system %iowait %steal %idle\n";
        $expected = ['cpuIowaitPct' => 40.0, 'cpuBusyPct' => 6.0, 'cpuStealPct' => 4.0, 'cpuIdlePct' => 50.0];
        $this->assertSame($expected, \pmssDiskIostatParseCpuFields($header."90 0 0 1 0 9\nDevice r/s\ngrp1 1\n".$header."1 2 3 40 4 50\n"));
        $this->assertSame($expected, \pmssDiskIostatParseCpuFields("avg-cpu: %idle %steal %iowait %system %nice %user\r\n50 4 40 3 2 1\r\n"));
        $this->assertSame(['cpuIowaitPct' => 0.0, 'cpuBusyPct' => 0.0, 'cpuStealPct' => 0.0, 'cpuIdlePct' => 100.0],
            \pmssDiskIostatParseCpuFields($header."0 0 0 0 0 100\n"));
    }

    public function testMalformedOrMissingFinalCpuIsNullable(): void
    {
        $header = "avg-cpu: %user %nice %system %iowait %steal %idle\n";
        $nulls = array_fill_keys(['cpuIowaitPct', 'cpuBusyPct', 'cpuStealPct', 'cpuIdlePct'], null);
        foreach (['', 'Device r/s', $header, $header."1 2 3\n", $header."1 2 3 bad 4 50\n",
            $header."1 2 3 101 4 50\n", $header."1 2 3 -1 4 50\n", $header."1 2 3 1e1 4 50\n",
            $header."1 2 3 40\0 4 50\n", $header."1 2 3 40 4 50\0\n", $header."1 2 3 40 4 50\n".$header] as $raw) {
            $this->assertSame($nulls, \pmssDiskIostatParseCpuFields($raw));
        }
    }

    public function testProcEvidenceIncludesSyncKindsAndRetainsZero(): void
    {
        $root = $this->pmssMakeTempDir('pmss-contention-');
        $this->pmssWriteFiles($root, ['pressure/io' => "some avg300=4.25\nfull avg300=1\n",
            'stat' => "cpu 1 2 3\nprocs_blocked 0\n", 'meminfo' => "Dirty: 128 kB\nWriteback: 0 kB\n"]);
        foreach (['resync', 'recovery', 'check', 'reshape', 'idle'] as $kind) {
            $this->pmssWriteFile($root.'/mdstat', $kind === 'idle' ? "md0 : active raid1\n" : " [=>...] $kind = 2.5%\n");
            $this->assertSame(['psiSomeAvg300' => 4.25, 'procsBlocked' => 0, 'dirtyKb' => 128,
                'writebackKb' => 0, 'mdSyncActive' => $kind !== 'idle'], \pmssDiskIostatReadContention($root));
        }
    }

    public function testMissingAndMalformedProcEvidenceStaysUnavailable(): void
    {
        $root = $this->pmssMakeTempDir('pmss-contention-missing-');
        $nulls = array_fill_keys(['psiSomeAvg300', 'procsBlocked', 'dirtyKb', 'writebackKb', 'mdSyncActive'], null);
        $this->assertSame($nulls, \pmssDiskIostatReadContention($root));
        foreach (['-1', 'bad', '1 extra', str_repeat('9', 30), "1\0"] as $value) {
            $this->pmssWriteFile($root.'/stat', 'procs_blocked '.$value."\n");
            $this->assertSame(null, \pmssDiskIostatReadContention($root)['procsBlocked']);
        }
        $this->pmssWriteFiles($root, ['pressure/io' => "some avg300=101\n", 'meminfo' => 'Dirty: '.str_repeat('9', 30)." kB\nWriteback: invalid\n"]);
        $this->assertSame($nulls, \pmssDiskIostatReadContention($root));
    }
}
