#!/usr/bin/php
<?php
/**
 * Non-destructive storage benchmark (fio wrapper)
 *
 * Runs a small stack of fio tests representative of shared-storage seedbox
 * workloads and logs parseable JSON results. Tests operate on a temporary file
 * under the chosen target directory and remove it afterwards.
 *
 * Usage (examples):
 *   - php scripts/util/benchmarkStorage.php
 *   - php scripts/util/benchmarkStorage.php --target=/home --size=2G --runtime=45
 *   - php scripts/util/benchmarkStorage.php --json=/var/log/pmss/benchmark-storage.jsonl
 *
 * Defaults aim to be safe and non-destructive: time-based, direct I/O, and a
 * private test file with a fixed size.
 */

require_once __DIR__.'/../lib/runtime.php';

function printUsage(): void
{
    echo "\nStorage benchmark (non-destructive)\n";
    echo "Usage: benchmarkStorage.php [options]\n\n";
    echo "Core options:\n";
    echo "  --target=<dir>            Directory for file-backed tests (default /home)\n";
    echo "  --size=<bytes|MiB|GiB>    Target file size (default 500G, capped to 80% free)\n";
    echo "  --runtime=<seconds>       Per-test runtime for volume fio tests (default 60)\n";
    echo "  --label=<name>            Tag results (e.g., hostname/site/array)\n";
    echo "  --json=<path>             JSON Lines log (default /var/log/pmss/benchmark-storage.jsonl)\n\n";
    echo "Device options (read-only):\n";
    echo "  --devices                 Enable per-device tests (dd seqread + fio randread)\n";
    echo "  --dd-size=<MiB|GiB>       Size for dd seqread per device (default 1G)\n";
    echo "  --device-runtime=<sec>    Per-device fio runtime (default 30)\n\n";
    echo "Idle checks:\n";
    echo "  --require-idle            Abort if busy (ioping/iostat exceed thresholds)\n";
    echo "  --idle-latency-ms=<ms>    ioping avg latency threshold (default 100)\n";
    echo "  --idle-util=<percent>     iostat util threshold (default 85)\n\n";
    echo "Other:\n";
    echo "  --show-last               Print the last run's human summary and exit\n";
    echo "  --help                    Show this help\n\n";
    echo "Examples:\n";
    echo "  php scripts/util/benchmarkStorage.php --devices --label=initial\n";
    echo "  php scripts/util/benchmarkStorage.php --require-idle --devices --dd-size=2G --device-runtime=45\n";
    echo "  php scripts/util/benchmarkStorage.php --show-last\n\n";
}

// --- CLI args ---
$targetDir = '/home';
$fileSize  = '500G';
$runtime   = 60;       // seconds per test
$jsonLog   = '/var/log/pmss/benchmark-storage.jsonl';
$label     = '';
$testDevices = false;
$ddSize   = '1G';       // per-device sequential read size
$devRuntime = 30;       // seconds per-device fio tests
$requireIdle = false;   // if true, abort when busy; default: warn only
$idleLatencyMs = 100;   // ioping average threshold (HDD sane limit ~100ms)
$idleUtilPct   = 85;    // iostat disk util threshold
$showLast = false;      // display last run results and exit

foreach ($argv as $arg) {
    if (strpos($arg, '--target=') === 0) {
        $targetDir = substr($arg, 9);
    } elseif (strpos($arg, '--size=') === 0) {
        $fileSize = substr($arg, 7);
    } elseif (strpos($arg, '--runtime=') === 0) {
        $runtime = (int) substr($arg, 10);
    } elseif (strpos($arg, '--json=') === 0) {
        $jsonLog = substr($arg, 7);
    } elseif (strpos($arg, '--label=') === 0) {
        $label = substr($arg, 8);
    } elseif (strpos($arg, '--dd-size=') === 0) {
        $ddSize = substr($arg, 10);
    } elseif (strpos($arg, '--device-runtime=') === 0) {
        $devRuntime = (int) substr($arg, 18);
    } elseif ($arg === '--devices') {
        $testDevices = true;
    } elseif ($arg === '--require-idle') {
        $requireIdle = true;
    } elseif (strpos($arg, '--idle-latency-ms=') === 0) {
        $idleLatencyMs = (int) substr($arg, 18);
    } elseif (strpos($arg, '--idle-util=') === 0) {
        $idleUtilPct = (int) substr($arg, 13);
    } elseif ($arg === '--show-last') {
        $showLast = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        printUsage();
        exit(0);
    }
}

// --- Preconditions ---
if (trim((string) shell_exec('command -v fio 2>/dev/null')) === '') {
    fwrite(STDERR, "Error: 'fio' not found in PATH. Install 'fio' and retry.\n");
    exit(1);
}
if (!is_dir($targetDir) || !is_writable($targetDir)) {
    fwrite(STDERR, "Error: target directory not writable: {$targetDir}\n");
    exit(1);
}

// Create log directory if needed
$logDir = dirname($jsonLog);
if (!is_dir($logDir) && !@mkdir($logDir, 0755, true)) {
    $jsonLog = sys_get_temp_dir().'/benchmark-storage.jsonl';
}

// Identify filesystem + device for context
$fs = trim((string) shell_exec('stat -f -c %T '.escapeshellarg($targetDir).' 2>/dev/null'));
$dev = trim((string) shell_exec('df -P '.escapeshellarg($targetDir).' | awk ' . escapeshellarg('NR==2 {print $1}') . ' 2>/dev/null'));

// Temporary test file
$testFile = rtrim($targetDir, '/').'/pmss-fio-'.bin2hex(random_bytes(4)).'.dat';

// Run identity (for grouping log entries of a single benchmark pass)
$runId = date('YmdHis').'-'.bin2hex(random_bytes(3));
$runTs = date('c');

// Convert human size to bytes
function parseSize(string $s): int {
    $s = trim($s);
    if ($s === '') return 0;
    if (preg_match('/^([0-9]+)([KkMmGgTt]i?[Bb]?)?$/', $s, $m)) {
        $n = (int)$m[1];
        $u = isset($m[2]) ? strtolower($m[2]) : '';
        switch ($u) {
            case 'k': case 'kb': case 'kib': return $n * 1024;
            case 'm': case 'mb': case 'mib': return $n * 1024 * 1024;
            case 'g': case 'gb': case 'gib': return $n * 1024 * 1024 * 1024;
            case 't': case 'tb': case 'tib': return $n * 1024 * 1024 * 1024 * 1024;
            default: return $n; // bytes
        }
    }
    return 0;
}

function runShell(string $cmd): array {
    $rc = runCommand($cmd, true);
    $out = $GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stdout'] ?? '';
    $err = $GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stderr'] ?? '';
    return [$rc, $out, $err];
}

// Pretty printing helpers
$ANSI = [
    'reset' => "\033[0m",
    'red'   => "\033[31m",
    'green' => "\033[32m",
    'yellow'=> "\033[33m",
    'blue'  => "\033[34m",
    'cyan'  => "\033[36m",
    'bold'  => "\033[1m",
];
function color(string $s, string $c, array $ANSI) { return $ANSI[$c].$s.$ANSI['reset']; }

function showLastRun(string $jsonLog, array $ANSI): void {
    if (!is_file($jsonLog)) {
        fwrite(STDERR, "No log found at {$jsonLog}\n");
        exit(1);
    }
    $fh = fopen($jsonLog, 'r');
    if (!$fh) { fwrite(STDERR, "Unable to read log {$jsonLog}\n"); exit(1); }
    $entries = [];
    $lastRunId = null; $lastRunTs = '';
    while (($line = fgets($fh)) !== false) {
        $j = json_decode($line, true);
        if (!is_array($j) || empty($j['run_id'])) continue;
        $entries[] = $j;
        if (!empty($j['run_ts']) && $j['run_ts'] > $lastRunTs) {
            $lastRunTs = $j['run_ts'];
            $lastRunId = $j['run_id'];
        }
    }
    fclose($fh);
    if ($lastRunId === null) { echo "No runs found.\n"; exit(0); }
    $run = array_values(array_filter($entries, static function($e) use ($lastRunId){ return ($e['run_id'] ?? '') === $lastRunId; }));
    if (empty($run)) { echo "No entries for last run.\n"; exit(0); }

    $label = $run[0]['label'] ?? '';
    echo color("\n== Storage benchmark (last run) ==\n", 'bold', $ANSI);
    echo "Run ID: {$lastRunId}  Time: ".($run[0]['run_ts'] ?? '?').($label?"  Label: {$label}":'')."\n\n";

    // Preflight
    $pre = null;
    foreach ($run as $e) { if (($e['test'] ?? '') === 'preflight-idle') { $pre = $e; break; } }
    if ($pre) {
        echo color("Preflight (idle)", 'cyan', $ANSI)."\n";
        $iop = $pre['ioping_avg_ms'] ?? null; $util = $pre['iostat_util_pct'] ?? null;
        $iopTxt = $iop !== null ? sprintf('ioping avg: %.2f ms', $iop) : 'ioping: n/a';
        $utilTxt = $util !== null ? sprintf('disk util: %.1f%%', $util) : 'iostat: n/a';
        $ok = ($pre['ok'] ?? true);
        echo ($ok ? color(' OK ', 'green', $ANSI) : color(' WARN ', 'yellow', $ANSI))."  {$iopTxt}; {$utilTxt}\n\n";
    }

    // File-backed results
    $fileTests = array_filter($run, static function($e){ return isset($e['test']) && empty($e['device']) && $e['test'] !== 'preflight-idle' && ($e['params']['rw'] ?? '') !== ''; });
    if (!empty($fileTests)) {
        echo color("File-backed volume tests", 'cyan', $ANSI)."\n";
        echo "test\tread_MB/s\twrite_MB/s\tread_IOPS\twrite_IOPS\tread_p95_ms\twrite_p95_ms\n";
        foreach ($fileTests as $e) {
            $m = $e['metrics'] ?? [];
            printf("%s\t%.2f\t%.2f\t%.1f\t%.1f\t%.2f\t%.2f\n",
                $e['test'], $m['read_bw_MBps'] ?? 0, $m['write_bw_MBps'] ?? 0, $m['read_iops'] ?? 0, $m['write_iops'] ?? 0, $m['read_p95_ms'] ?? 0, $m['write_p95_ms'] ?? 0);
        }
        echo "\n";
    }

    // Per-device results
    $devGroups = [];
    foreach ($run as $e) {
        if (isset($e['device'])) $devGroups[$e['device']][] = $e;
    }
    if (!empty($devGroups)) {
        echo color("Per-device read-only tests", 'cyan', $ANSI)."\n";
        foreach ($devGroups as $dev => $entriesDev) {
            echo color($dev, 'blue', $ANSI)."\n";
            foreach ($entriesDev as $e) {
                $t = $e['test']; $m = $e['metrics'] ?? [];
                if ($t === 'device-seqread-dd') {
                    printf("  %-18s  seq_MB/s=%.2f  t=%.2fs\n", $t, $m['seqread_MBps'] ?? 0, $m['elapsed_s'] ?? 0);
                } elseif (strpos($t, 'dev-randread') === 0) {
                    printf("  %-18s  read_MB/s=%.2f  read_IOPS=%.1f  p95_ms=%.2f\n",
                        $t, $m['read_bw_MBps'] ?? 0, $m['read_iops'] ?? 0, $m['read_p95_ms'] ?? 0);
                }
            }
        }
        echo "\n";
    }
}

if ($showLast) {
    showLastRun($jsonLog, $ANSI);
    exit(0);
}

// ---- Preflight: idle checks (ioping + iostat log) ----
function iopingAverageMs(string $dir): ?float {
    $bin = trim((string) shell_exec('command -v ioping 2>/dev/null'));
    if ($bin === '') return null;
    $cmd = escapeshellcmd($bin).' -c 10 -i 0.1 -D '.escapeshellarg($dir).' 2>&1 | tail -n 1';
    $out = trim((string) shell_exec($cmd));
    if ($out === '') return null;
    // Expect: min/avg/max/mdev = 123 us / 2.01 ms / 10.34 ms / 1.23 ms
    if (preg_match('/min\/avg\/max\/mdev\s*=\s*[^\/]+\/\s*([0-9.]+)\s*(us|ms|s)\s*\//i', $out, $m)) {
        $val = (float) $m[1]; $unit = strtolower($m[2]);
        if ($unit === 'us') return $val/1000.0;
        if ($unit === 'ms') return $val;
        if ($unit === 's')  return $val*1000.0;
    }
    return null;
}

function readIostatBusyPct(): ?float {
    $file = '/var/run/pmss/iostat';
    if (!is_file($file)) return null;
    $mtime = @filemtime($file);
    if ($mtime === false || (time() - $mtime) > 600) return null; // stale >10 min
    $data = @file_get_contents($file);
    if ($data === false) return null;
    $arr = @unserialize($data);
    if (!is_array($arr) || !isset($arr['diskUtil'])) return null;
    return (float) $arr['diskUtil'];
}

// Evaluate preflight and log
$preflight = [ 'timestamp' => date('c'), 'label' => $label !== '' ? $label : null, 'target_dir' => $targetDir, 'test' => 'preflight-idle', 'ok' => true ];
$avgMs = iopingAverageMs($targetDir);
if ($avgMs !== null) {
    $preflight['ioping_avg_ms'] = round($avgMs, 2);
    if ($avgMs > $idleLatencyMs) {
        $preflight['ok'] = false;
        $preflight['warn'] = 'ioping average above threshold';
        echo "[WARN] ioping avg latency ".$avgMs." ms exceeds ".$idleLatencyMs." ms\n";
    }
} else {
    $preflight['ioping'] = 'missing';
}
$util = readIostatBusyPct();
if ($util !== null) {
    $preflight['iostat_util_pct'] = round($util, 1);
    if ($util > $idleUtilPct) {
        $preflight['ok'] = false;
        $preflight['warn_util'] = 'iostat disk util above threshold';
        echo "[WARN] iostat util ".$util."% exceeds ".$idleUtilPct."%\n";
    }
} else {
    $preflight['iostat'] = 'missing_or_stale';
}
@file_put_contents($jsonLog, json_encode($preflight, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
if ($requireIdle && !$preflight['ok']) {
    fwrite(STDERR, "Busy system detected and --require-idle set; aborting benchmark.\n");
    exit(2);
}

// Test set (file-backed): tuned for shared storage/seedbox usage.
// - Mixed random, read-heavy, variable block sizes up to 1024k (~700k average)
// - Complementary random-read and small-block tests
$tests = [
    [
        'name'      => 'randmix-large-95r5w',
        'rw'        => 'randrw',
        'rwmixread' => 95,
        'bssplit'   => '4k/2:64k/3:128k/5:256k/10:512k/20:768k/25:1024k/35',
        'iodepth'   => 32,
        'numjobs'   => 4,
        'direct'    => 1,
    ],
    [
        'name'      => 'randread-large',
        'rw'        => 'randread',
        'bs'        => '1M',
        'iodepth'   => 32,
        'numjobs'   => 4,
        'direct'    => 1,
    ],
    [
        'name'      => 'randread-small',
        'rw'        => 'randread',
        'bs'        => '4k',
        'iodepth'   => 64,
        'numjobs'   => 4,
        'direct'    => 1,
    ],
    [
        'name'      => 'randwrite-small-short',
        'rw'        => 'randwrite',
        'bs'        => '4k',
        'iodepth'   => 32,
        'numjobs'   => 2,
        'direct'    => 1,
        'runtime'   => max(15, (int) floor($runtime / 3)),
    ],
    [
        'name'      => 'seqread-large',
        'rw'        => 'read',
        'bs'        => '1M',
        'iodepth'   => 32,
        'numjobs'   => 2,
        'direct'    => 1,
    ],
];

// Helper: run a single fio job and parse JSON results
function runFioJob(string $file, int $sizeBytes, int $runtime, array $job): array
{
    $fioJson = sys_get_temp_dir().'/fio-'.bin2hex(random_bytes(4)).'.json';
    $opts = [
        '--name='.escapeshellarg($job['name']),
        '--filename='.escapeshellarg($file),
        '--size='.$sizeBytes,
        '--time_based=1',
        '--runtime='.(int) ($job['runtime'] ?? $runtime),
        '--rw='.escapeshellarg($job['rw']),
        '--ioengine=libaio',
        '--iodepth='.(int) $job['iodepth'],
        '--numjobs='.(int) $job['numjobs'],
        '--direct='.(int) $job['direct'],
        '--group_reporting=1',
    ];
    if (isset($job['bs'])) {
        $opts[] = '--bs='.escapeshellarg($job['bs']);
    }
    if (isset($job['bssplit'])) {
        $opts[] = '--bssplit='.escapeshellarg($job['bssplit']);
    }
    if (isset($job['rwmixread'])) {
        $opts[] = '--rwmixread='.(int) $job['rwmixread'];
    }

    $cmd = 'fio --output-format=json --output '.escapeshellarg($fioJson).' '.implode(' ', $opts);
    $rc  = runCommand($cmd, true);
    $payload = @file_get_contents($fioJson);
    @unlink($fioJson);
    if ($rc !== 0 || $payload === false || trim($payload) === '') {
        return ['ok' => false, 'error' => 'fio failed or produced no JSON output', 'rc' => $rc];
    }
    $json = json_decode($payload, true);
    if (!is_array($json) || empty($json['jobs'][0])) {
        return ['ok' => false, 'error' => 'invalid fio JSON'];
    }

    // Aggregate simple metrics across jobs
    $read_bw = 0; $write_bw = 0; $read_iops = 0; $write_iops = 0;
    $read_p95 = []; $write_p95 = [];
    foreach ($json['jobs'] as $j) {
        $read_bw += (int) ($j['read']['bw_bytes'] ?? 0);
        $write_bw += (int) ($j['write']['bw_bytes'] ?? 0);
        $read_iops += (float) ($j['read']['iops'] ?? 0);
        $write_iops += (float) ($j['write']['iops'] ?? 0);
        $rp = $j['read']['clat_ns']['percentile']['95.000000'] ?? null;
        $wp = $j['write']['clat_ns']['percentile']['95.000000'] ?? null;
        if ($rp !== null) $read_p95[] = (float) $rp;
        if ($wp !== null) $write_p95[] = (float) $wp;
    }
    $avg = function(array $arr): float { return count($arr) ? array_sum($arr)/count($arr) : 0.0; };
    $res = [
        'read_bw_MBps'  => round($read_bw / (1024*1024), 2),
        'write_bw_MBps' => round($write_bw / (1024*1024), 2),
        'read_iops'     => round($read_iops, 1),
        'write_iops'    => round($write_iops, 1),
        'read_p95_ms'   => round($avg($read_p95) / 1_000_000, 2),
        'write_p95_ms'  => round($avg($write_p95) / 1_000_000, 2),
        'raw'           => $json,
    ];
    return ['ok' => true, 'result' => $res];
}

// Decide on actual file size: minimum of requested and 80% of free space.
$requestedBytes = parseSize($fileSize);
$freeBytes = (int) trim((string) shell_exec('df -PB1 '.escapeshellarg($targetDir).' | awk ' . escapeshellarg('NR==2 {print $4}') . ' 2>/dev/null'));
$capBytes  = (int) floor($freeBytes * 0.80);
$useBytes  = $requestedBytes > 0 ? min($requestedBytes, $capBytes) : $capBytes;
if ($requestedBytes > 0 && $useBytes < $requestedBytes) {
    echo "[WARN] Requested size {$fileSize} exceeds safe free space. Using ".round($useBytes/(1024*1024*1024),2)." GiB.\n";
}
if ($useBytes <= 0) {
    fwrite(STDERR, "Error: insufficient free space under {$targetDir} to run benchmark.\n");
    exit(1);
}

// Precreate the test file (fast) to avoid interference between jobs.
// Fallback to fio creating it if fallocate is unavailable.
if (trim((string) shell_exec('command -v fallocate 2>/dev/null')) !== '') {
    runCommand('fallocate -l '.$useBytes.' '.escapeshellarg($testFile));
}

$summary = [];
$ts = date('c');

foreach ($tests as $job) {
    $res = runFioJob($testFile, $useBytes, $runtime, $job);
    $entry = [
        'timestamp'  => $ts,
        'label'      => $label !== '' ? $label : null,
        'target_dir' => $targetDir,
        'device'     => $dev,
        'filesystem' => $fs,
        'test'       => $job['name'],
        'params'     => [
            'rw' => $job['rw'],
            'rwmixread' => $job['rwmixread'] ?? null,
            'bs' => $job['bs'] ?? null,
            'bssplit' => $job['bssplit'] ?? null,
            'iodepth' => $job['iodepth'],
            'numjobs' => $job['numjobs'],
            'direct'  => $job['direct'],
            'runtime' => (int) ($job['runtime'] ?? $runtime),
            'size_bytes' => $useBytes,
        ],
        'ok'         => $res['ok'],
    ];
    if ($res['ok']) {
        $entry['metrics'] = $res['result'];
        $summary[] = [
            $job['name'],
            $res['result']['read_bw_MBps'],
            $res['result']['write_bw_MBps'],
            $res['result']['read_iops'],
            $res['result']['write_iops'],
            $res['result']['read_p95_ms'],
            $res['result']['write_p95_ms'],
        ];
    } else {
        $entry['error'] = $res['error'] ?? 'unknown error';
    }

    // Append JSONL log
    @file_put_contents($jsonLog, json_encode($entry, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
}

@unlink($testFile);

// Print a human-readable summary
echo "\n== Storage benchmark summary (".($label !== '' ? $label.' ' : '')."on {$targetDir}) ==\n";
echo "test\tread_MB/s\twrite_MB/s\tread_IOPS\twrite_IOPS\tread_p95_ms\twrite_p95_ms\n";
foreach ($summary as $row) {
    printf("%s\t%.2f\t%.2f\t%.1f\t%.1f\t%.2f\t%.2f\n", ...$row);
}

echo "\nJSON log: {$jsonLog}\n";

// ---- Optional per-device read-only benchmarking ----
if ($testDevices) {
    echo "\n== Per-device read-only benchmarks ==\n";
    // Enumerate disks with metadata
    $ls = shell_exec('lsblk -dn -o KNAME,TYPE,ROTA,MODEL,SERIAL,SIZE 2>/dev/null');
    $devices = [];
    if ($ls) {
        $lines = preg_split('/\r?\n/', trim($ls));
        foreach ($lines as $line) {
            if ($line === '') continue;
            // KNAME TYPE ROTA MODEL ... SERIAL SIZE
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 3) continue;
            $kname = $parts[0];
            $type  = $parts[1];
            $rota  = (int) $parts[2];
            if ($type !== 'disk') continue;
            // Skip loop/ram devices
            if (strpos($kname, 'loop') === 0 || strpos($kname, 'ram') === 0) continue;
            $path = '/dev/'.$kname;
            // Model and serial may have spaces; reconstruct from known tail SIZE and head 3 fields
            $sizeStr = $parts[count($parts)-1] ?? '';
            $serial  = $parts[count($parts)-2] ?? '';
            $modelArr = array_slice($parts, 3, max(0, count($parts)-5));
            $model = implode(' ', $modelArr);
            $devices[] = [
                'path'   => $path,
                'kname'  => $kname,
                'rota'   => $rota,
                'model'  => $model,
                'serial' => $serial,
                'size'   => $sizeStr,
            ];
        }
    }
    $devResults = [];
    foreach ($devices as $devMeta) {
        $path = $devMeta['path'];
        if (!is_readable($path)) continue;
        $sizeBytes = (int) trim((string) shell_exec('blockdev --getsize64 '.escapeshellarg($path).' 2>/dev/null'));
        // dd sequential read from random offset
        $readBytes = parseSize($ddSize);
        if ($readBytes <= 0) $readBytes = 1024*1024*1024;
        $skipBlocks = 0;
        if ($sizeBytes > ($readBytes + 4*1024*1024)) {
            $maxSkipBlocks = (int) floor(($sizeBytes - $readBytes) / (1024*1024));
            $skipBlocks = $maxSkipBlocks > 0 ? random_int(0, $maxSkipBlocks) : 0;
        }
        $ddCmd = sprintf('dd if=%s of=/dev/null bs=1M count=%d skip=%d iflag=direct 2>&1',
            escapeshellarg($path), (int) floor($readBytes/(1024*1024)), $skipBlocks);
        [$rc, $stdout, $stderr] = runShell($ddCmd);
        $line = trim($stderr !== '' ? $stderr : $stdout);
        $mbps = null; $secs = null;
        if (preg_match('/\s([0-9.]+)\s+s,\s+([0-9.]+)\s+MB\/s/', $line, $m)) {
            $secs = (float) $m[1];
            $mbps = (float) $m[2];
        }
        $entry = [
            'timestamp' => date('c'),
            'label'     => $label !== '' ? $label : null,
            'run_id'    => $runId,
            'run_ts'    => $runTs,
            'device'    => $path,
            'model'     => $devMeta['model'],
            'serial'    => $devMeta['serial'],
            'rota'      => $devMeta['rota'],
            'size'      => $devMeta['size'],
            'test'      => 'device-seqread-dd',
            'params'    => ['bs' => '1M', 'count' => (int) floor($readBytes/(1024*1024)), 'skip_blocks' => $skipBlocks],
            'ok'        => $rc === 0 && $mbps !== null,
        ];
        if ($mbps !== null) {
            $entry['metrics'] = ['seqread_MBps' => $mbps, 'elapsed_s' => $secs];
        } else {
            $entry['error'] = 'dd parse failed';
        }
        @file_put_contents($jsonLog, json_encode($entry, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
        printf("%s\tdd_seqread_MB/s=%s\n", $path, $mbps !== null ? number_format($mbps,2) : 'n/a');

        // Per-device ioping (direct I/O)
        $dioping = iopingAverageMs($path);
        $iopEntry = [
            'timestamp' => date('c'),
            'label'     => $label !== '' ? $label : null,
            'run_id'    => $runId,
            'run_ts'    => $runTs,
            'device'    => $path,
            'model'     => $devMeta['model'],
            'serial'    => $devMeta['serial'],
            'rota'      => $devMeta['rota'],
            'size'      => $devMeta['size'],
            'test'      => 'device-ioping',
            'ok'        => $dioping !== null,
        ];
        if ($dioping !== null) {
            $iopEntry['metrics'] = ['ioping_avg_ms' => round($dioping,2)];
            printf("%s\tioping_avg_ms=%.2f\n", $path, $dioping);
        } else {
            $iopEntry['error'] = 'ioping missing or parse failed';
        }
        @file_put_contents($jsonLog, json_encode($iopEntry, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);

        // fio random read small and large on device
        $devJobs = [
            ['name' => 'dev-randread-4k', 'rw' => 'randread', 'bs' => '4k', 'iodepth' => 64, 'numjobs' => 1, 'direct' => 1],
            ['name' => 'dev-randread-1M', 'rw' => 'randread', 'bs' => '1M', 'iodepth' => 32, 'numjobs' => 1, 'direct' => 1],
        ];
        foreach ($devJobs as $job) {
            $res = runFioJob($path, $sizeBytes, $devRuntime, $job);
            $e = [
                'timestamp' => date('c'),
                'label'     => $label !== '' ? $label : null,
                'run_id'    => $runId,
                'run_ts'    => $runTs,
                'device'    => $path,
                'model'     => $devMeta['model'],
                'serial'    => $devMeta['serial'],
                'rota'      => $devMeta['rota'],
                'size'      => $devMeta['size'],
                'test'      => $job['name'],
                'params'    => ['rw'=>$job['rw'],'bs'=>$job['bs'],'iodepth'=>$job['iodepth'],'numjobs'=>$job['numjobs'],'runtime'=>$devRuntime],
                'ok'        => $res['ok'],
            ];
            if ($res['ok']) {
                $e['metrics'] = $res['result'];
                printf("%s\t%s\tread_MB/s=%.2f\tread_iops=%.1f\tread_p95_ms=%.2f\n",
                    $path, $job['name'], $res['result']['read_bw_MBps'], $res['result']['read_iops'], $res['result']['read_p95_ms']);
            } else {
                $e['error'] = $res['error'] ?? 'fio failed';
            }
            @file_put_contents($jsonLog, json_encode($e, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
            // keep for summary
            if ($res['ok'] && $job['name'] === 'dev-randread-4k') {
                $devResults[$path]['fio4k_mb'] = $res['result']['read_bw_MBps'];
            }
        }
        // Store device-level results for checks
        $devResults[$path]['dd_mb']  = $mbps;
        $devResults[$path]['iop_ms'] = $dioping;
        $devResults[$path]['meta']   = $devMeta;
    }

    // Simple anomaly detection vs peers (loose thresholds)
    $vals = function(array $devResults, string $key): array { $arr=[]; foreach($devResults as $p=>$d){ if(isset($d[$key]) && $d[$key]!==null) $arr[]=$d[$key]; } return $arr; };
    $median = function(array $arr): float { sort($arr); $n=count($arr); if($n===0) return 0.0; $m=(int)floor(($n-1)/2); return $n%2? (float)$arr[$m] : (($arr[$m]+$arr[$m+1])/2); };
    $medDd  = $median($vals($devResults,'dd_mb'));
    $medIop = $median($vals($devResults,'iop_ms'));
    $med4k  = $median($vals($devResults,'fio4k_mb'));
    $flags  = [];
    foreach ($devResults as $path => $resv) {
        if ($medDd>0 && isset($resv['dd_mb']) && $resv['dd_mb']!==null && $resv['dd_mb'] < 0.6*$medDd) {
            $flags[] = [ 'device'=>$path, 'reason'=>'seqread_slow', 'value'=>$resv['dd_mb'], 'median'=>$medDd ];
            echo color("WARN:", 'yellow', $ANSI)." {$path} seqread MB/s=".number_format($resv['dd_mb'],2)." < 60% of median ".number_format($medDd,2)."\n";
        }
        if ($medIop>0 && isset($resv['iop_ms']) && $resv['iop_ms']!==null && $resv['iop_ms'] > max(50, 2*$medIop)) {
            $flags[] = [ 'device'=>$path, 'reason'=>'ioping_slow', 'value'=>$resv['iop_ms'], 'median'=>$medIop ];
            echo color("WARN:", 'yellow', $ANSI)." {$path} ioping avg=".number_format($resv['iop_ms'],2)." ms > 2x median ".number_format($medIop,2)." ms\n";
        }
        if ($med4k>0 && isset($resv['fio4k_mb']) && $resv['fio4k_mb']!==null && $resv['fio4k_mb'] < 0.5*$med4k) {
            $flags[] = [ 'device'=>$path, 'reason'=>'rand4k_slow', 'value'=>$resv['fio4k_mb'], 'median'=>$med4k ];
            echo color("WARN:", 'yellow', $ANSI)." {$path} randread 4k MB/s=".number_format($resv['fio4k_mb'],2)." < 50% of median ".number_format($med4k,2)."\n";
        }
    }
    // Array-level coarse check (HDDs expected >= ~120 MB/s when healthy)
    $hddCount = 0; foreach ($devResults as $r) { if (($r['meta']['rota'] ?? 0) == 1) $hddCount++; }
    if ($hddCount>0 && $medDd>0 && $medDd < 80) {
        echo color("WARN:", 'yellow', $ANSI)." array median seqread low (".number_format($medDd,2)." MB/s across {$hddCount} HDDs)\n";
    }
    $summary = [
        'timestamp'=> date('c'),
        'label'    => $label !== '' ? $label : null,
        'run_id'   => $runId,
        'run_ts'   => $runTs,
        'test'     => 'device-summary',
        'medians'  => ['seqread_MBps'=>$medDd, 'ioping_ms'=>$medIop, 'rand4k_MBps'=>$med4k],
        'flags'    => $flags,
    ];
    @file_put_contents($jsonLog, json_encode($summary, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
}
