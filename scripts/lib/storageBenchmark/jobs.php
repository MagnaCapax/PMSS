<?php
/** Ordered fio jobs and one execution/logging sequence shared by all benchmark modes. */

function storageBenchmarkFileJobs(?int $runtime = null): array { $jobs = [['name' => 'randmix-large-95r5w', 'rw' => 'randrw', 'rwmixread' => 95, 'bssplit' => '4k/2:64k/3:128k/5:256k/10:512k/20:768k/25:1024k/35', 'iodepth' => 32, 'numjobs' => 4, 'direct' => 1], ['name' => 'randread-large', 'rw' => 'randread', 'bs' => '1M', 'iodepth' => 32, 'numjobs' => 4, 'direct' => 1], ['name' => 'randread-small', 'rw' => 'randread', 'bs' => '4k', 'iodepth' => 64, 'numjobs' => 4, 'direct' => 1], ['name' => 'randwrite-small-short', 'rw' => 'randwrite', 'bs' => '4k', 'iodepth' => 32, 'numjobs' => 2, 'direct' => 1], ['name' => 'seqread-large', 'rw' => 'read', 'bs' => '1M', 'iodepth' => 32, 'numjobs' => 2, 'direct' => 1]]; if ($runtime !== null) $jobs[3]['runtime'] = max(15, (int) floor($runtime / 3)); return $jobs; }
function storageBenchmarkDeviceJobs(): array { return [['name' => 'dev-randread-4k', 'rw' => 'randread', 'bs' => '4k', 'iodepth' => 64], ['name' => 'dev-randread-1M', 'rw' => 'randread', 'bs' => '1M', 'iodepth' => 32]]; }

/** Mounted-device jobs explicitly retain fio's read-only guard. */
const PMSS_STORAGE_BENCHMARK_HOME_DEVICE_JOBS = [
    ['name' => 'home-device-randread-4k', 'rw' => 'randread', 'bs' => '4k', 'iodepth' => 64, 'numjobs' => 1, 'direct' => 1, 'readonly' => true],
    ['name' => 'home-device-seqread-1M', 'rw' => 'read', 'bs' => '1M', 'iodepth' => 32, 'numjobs' => 1, 'direct' => 1, 'readonly' => true],
];

/** Yield each result only after its ordered JSONL entry is persisted; exceptions stop the sequence. */
function storageBenchmarkFioJobsRun(string $jsonLog, string $path, int $size, int $runtime, array $jobs, array $base, array $params, string $fallbackError = 'unknown', ?callable $runner = null): \Generator
{
    $run = $runner ?: 'fioRun';
    foreach ($jobs as $job) {
        $job += ['numjobs' => 1, 'direct' => 1];
        // The projection preserves each mode's parameter keys, nulls, and insertion order.
        $entry = $base + ['test' => $job['name'], 'params' => array_replace($params, array_intersect_key($job, $params), ['runtime' => (int) ($job['runtime'] ?? $runtime)])];
        $result = $run($path, $size, $runtime, $job);
        $entry['ok'] = $result['ok'];
        storageBenchmarkAppendJsonLine($jsonLog, storageBenchmarkApplyRunResult($entry, $result, $fallbackError));
        yield $job['name'] => $result;
    }
}

/** Run and report one dd job with the same offset policy in either device mode. */
function storageBenchmarkDdTestRun(string $jsonLog, string $path, array $row, string $test, int $size, int $ddSizeBytes, string $format, ?callable $runner = null): array
{
    $count = (int) floor($ddSizeBytes / (1024 * 1024));
    // Choose an in-bounds MiB offset with the existing four-MiB tail margin.
    $skip = $size > ($count * 1024 * 1024 + 4 * 1024 * 1024)
        ? random_int(0, (int) floor(($size - $count * 1024 * 1024) / (1024 * 1024))) : 0;
    $dd = ($runner ?: 'storageBenchmarkDdSeqread')($path, $count, $skip);
    storageBenchmarkAppendJsonLine($jsonLog, storageBenchmarkDdResultEntry($row, $test, $count, $skip, $dd));
    printf($format, $path, $dd['mbps'] !== null ? number_format($dd['mbps'], 2) : 'n/a');
    return $dd;
}
