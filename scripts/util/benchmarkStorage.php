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
    echo "Usage: benchmarkStorage.php [--target=<dir>] [--size=<bytes|MiB|GiB>] [--runtime=<seconds>] [--json=<path>] [--label=<name>]\n";
}

// --- CLI args ---
$targetDir = '/home';
$fileSize  = '500G';
$runtime   = 60;       // seconds per test
$jsonLog   = '/var/log/pmss/benchmark-storage.jsonl';
$label     = '';

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

// Test set: tuned for shared storage/seedbox usage.
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
