<?php
namespace PMSS\Tests;

class StorageBenchmarkShowLastTest extends TestCase
{
    private function writeLog(array $entries): string
    {
        $dir = sys_get_temp_dir().'/pmss-bench-'.bin2hex(random_bytes(3));
        @mkdir($dir, 0700, true);
        $log = $dir.'/benchmark-storage.jsonl';
        foreach ($entries as $e) {
            file_put_contents($log, json_encode($e)."\n", FILE_APPEND);
        }
        return $log;
    }

    public function testShowLastParsesAndPrintsSummary(): void
    {
        $runId  = '20250101010101-aaa';
        $runTs  = '2025-01-01T01:01:01Z';
        $older  = '2024-12-31T23:59:59Z';
        $entries = [
            // Older run we should ignore
            ['timestamp'=>$older,'run_id'=>'old','run_ts'=>$older,'test'=>'preflight-idle','ok'=>true],
            // Current run
            ['timestamp'=>$runTs,'run_id'=>$runId,'run_ts'=>$runTs,'test'=>'preflight-idle','ok'=>true,'ioping_avg_ms'=>1.23,'iostat_util_pct'=>3.0],
            ['timestamp'=>$runTs,'run_id'=>$runId,'run_ts'=>$runTs,'test'=>'randread-small','params'=>['rw'=>'randread'],
             'metrics'=>['read_bw_MBps'=>123.45,'write_bw_MBps'=>0,'read_iops'=>999.9,'write_iops'=>0,'read_p95_ms'=>2.34,'write_p95_ms'=>0]],
            // Device sample
            ['timestamp'=>$runTs,'run_id'=>$runId,'run_ts'=>$runTs,'device'=>'/dev/sda','test'=>'device-seqread-dd','metrics'=>['seqread_MBps'=>250.5,'elapsed_s'=>4.0]],
        ];
        $log = $this->writeLog($entries);
        $out = shell_exec('php '.escapeshellarg(dirname(__DIR__, 3).'/util/storageBenchmark.php').' --show-last --json '.escapeshellarg($log).' 2>&1');
        $this->assertTrue(is_string($out) && $out !== '', 'no output from storageBenchmark --show-last');
        $this->assertStringContainsString('== Storage benchmark (last run) ==', $out);
        $this->assertStringContainsString('File-backed tests', $out);
        $this->assertStringContainsString('randread-small', $out);
        $this->assertStringContainsString('Per-device tests', $out);
        $this->assertStringContainsString('/dev/sda', $out);
    }

    public function testShowLastAcceptsEqualsFormForJsonPath(): void
    {
        $runId  = '20250202020202-ccc';
        $runTs  = '2025-02-02T02:02:02Z';
        $entries = [
            ['timestamp'=>$runTs,'run_id'=>$runId,'run_ts'=>$runTs,'test'=>'preflight-idle','ok'=>true,'ioping_avg_ms'=>1.0,'iostat_util_pct'=>1.0],
            ['timestamp'=>$runTs,'run_id'=>$runId,'run_ts'=>$runTs,'test'=>'randread-small','params'=>['rw'=>'randread'],
             'metrics'=>['read_bw_MBps'=>10,'write_bw_MBps'=>0,'read_iops'=>10,'write_iops'=>0,'read_p95_ms'=>1,'write_p95_ms'=>0]],
        ];
        $log = $this->writeLog($entries);
        $script = dirname(__DIR__, 3).'/util/storageBenchmark.php';
        $out = shell_exec('php '.escapeshellarg($script).' --show-last --json='.escapeshellarg($log).' 2>&1');
        $this->assertStringContainsString('== Storage benchmark (last run) ==', (string)$out);
        $this->assertStringContainsString('randread-small', (string)$out);
    }

    public function testShowLastHandlesMalformedLines(): void
    {
        $runId  = '20250102020202-bbb';
        $runTs  = '2025-01-02T02:02:02Z';
        $log = $this->writeLog([
            '{this is not json}', // junk
            ['timestamp'=>$runTs,'run_id'=>$runId,'run_ts'=>$runTs,'test'=>'preflight-idle','ok'=>true],
            'BROKEN LINE',
            ['timestamp'=>$runTs,'run_id'=>$runId,'run_ts'=>$runTs,'test'=>'randread-small','params'=>['rw'=>'randread'],
             'metrics'=>['read_bw_MBps'=>1,'write_bw_MBps'=>0,'read_iops'=>1,'write_iops'=>0,'read_p95_ms'=>1,'write_p95_ms'=>0]],
        ]);
        $out = shell_exec('php '.escapeshellarg(dirname(__DIR__, 3).'/util/storageBenchmark.php').' --show-last --json '.escapeshellarg($log).' 2>&1');
        $this->assertStringContainsString('Storage benchmark (last run)', $out);
        $this->assertStringContainsString('randread-small', $out);
    }

    public function testShowLastSelectsLatestRunByTimestamp(): void
    {
        $older = '2025-01-03T00:00:00Z';
        $newer = '2025-01-03T01:00:00Z';
        $log = $this->writeLog([
            ['timestamp'=>$older,'run_id'=>'old','run_ts'=>$older,'test'=>'preflight-idle','ok'=>true],
            ['timestamp'=>$older,'run_id'=>'old','run_ts'=>$older,'test'=>'randread-small','params'=>['rw'=>'randread'],
             'metrics'=>['read_bw_MBps'=>1,'write_bw_MBps'=>0,'read_iops'=>1,'write_iops'=>0,'read_p95_ms'=>1,'write_p95_ms'=>0]],
            ['timestamp'=>$newer,'run_id'=>'new','run_ts'=>$newer,'test'=>'preflight-idle','ok'=>true],
            ['timestamp'=>$newer,'run_id'=>'new','run_ts'=>$newer,'test'=>'randread-small','params'=>['rw'=>'randread'],
             'metrics'=>['read_bw_MBps'=>2,'write_bw_MBps'=>0,'read_iops'=>2,'write_iops'=>0,'read_p95_ms'=>2,'write_p95_ms'=>0]],
        ]);
        $out = shell_exec('php '.escapeshellarg(dirname(__DIR__, 3).'/util/storageBenchmark.php').' --show-last --json '.escapeshellarg($log).' 2>&1');
        // Only the newer result value should appear
        $this->assertStringContainsString("\t2.00\t0.00\t2.0\t0.0\t2.00\t0.00", $out);
    }

    public function testShowLastPrintsPreflightOnlyIfNoTests(): void
    {
        $runTs  = '2025-01-04T03:03:03Z';
        $log = $this->writeLog([
            ['timestamp'=>$runTs,'run_id'=>'only','run_ts'=>$runTs,'test'=>'preflight-idle','ok'=>true,'ioping_avg_ms'=>12.3,'iostat_util_pct'=>40],
        ]);
        $out = shell_exec('php '.escapeshellarg(dirname(__DIR__, 3).'/util/storageBenchmark.php').' --show-last --json '.escapeshellarg($log).' 2>&1');
        $this->assertStringContainsString('Preflight: ioping=12.3 ms', $out);
    }
}
