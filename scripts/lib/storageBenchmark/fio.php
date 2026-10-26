<?php
/** Fio process lifetime and validated measurement decoding. @license GPL-3.0-only */

function fioRun(string $file, int $size, int $runtime, array $job): array
{
    $json = pmssCreatePrivateTempFile('fio-'); if ($json === null) return ['ok' => false, 'error' => 'unable to allocate fio JSON temp file'];
    try {
        $opts = ['--name='.escapeshellarg($job['name']), '--filename='.escapeshellarg($file), '--size='.$size, '--time_based=1', '--runtime='.(int) ($job['runtime'] ?? $runtime), '--rw='.escapeshellarg($job['rw']), '--ioengine=libaio', '--iodepth='.(int) $job['iodepth'], '--numjobs='.(int) $job['numjobs'], '--direct='.(int) $job['direct'], '--group_reporting=1']; if (isset($job['bs'])) $opts[] = '--bs='.escapeshellarg($job['bs']); if (isset($job['bssplit'])) $opts[] = '--bssplit='.escapeshellarg($job['bssplit']); if (isset($job['rwmixread'])) $opts[] = '--rwmixread='.(int) $job['rwmixread']; if (!empty($job['readonly'])) $opts[] = '--readonly'; $rc = runCommand('fio --output-format=json --output '.escapeshellarg($json).' '.implode(' ', $opts), true); $payload = @file_get_contents($json);
    } finally {
        // Release the private output even when quoting, execution, or reading throws.
        @unlink($json);
    }
    if ($rc !== 0 || $payload === false || trim($payload) === '') return ['ok' => false, 'error' => 'fio failed'];
    $payload = pmssJsonDecodeAssoc($payload);
    $invalid = ['ok' => false, 'error' => 'invalid fio JSON'];
    if ($payload === null || !is_array($payload['jobs'] ?? null) || empty($payload['jobs'][0])) return $invalid;
    // One direction record replaces independent read/write bandwidth, IOPS and latency state.
    $totals = array_fill_keys(['read', 'write'], ['bw' => 0, 'iops' => 0.0, 'p95' => []]);
    foreach ($payload['jobs'] as $job) {
        if (!is_array($job) || (isset($job['read']) && !is_array($job['read']))
            || (isset($job['write']) && !is_array($job['write']))) return $invalid;
        if (($job['error'] ?? 0) !== 0) return ['ok' => false, 'error' => 'fio failed'];
        foreach ($totals as $direction => &$total) {
            $raw = $job[$direction]['bw_bytes'] ?? 0;
            $bandwidth = is_int($raw) || is_string($raw) ? pmssUnsignedDecimalIntParse((string) $raw) : null;
            $iops = pmssFiniteNonNegativeFloat($job[$direction]['iops'] ?? 0);
            $percentile = $job[$direction]['clat_ns']['percentile']['95.000000'] ?? null;
            // Preserve unsigned counters, finite non-negative floats and overflow rejection.
            if ($bandwidth === null || $iops === null || $total['bw'] > PHP_INT_MAX - $bandwidth
                || ($percentile !== null && pmssFiniteNonNegativeFloat($percentile) === null)) return $invalid;
            $total['bw'] += $bandwidth;
            $total['iops'] += $iops;
            if ($percentile !== null) $total['p95'][] = (float) $percentile;
        }
        unset($total);
    }
    // Fix field order independently of the direction traversal for JSONL consumers.
    $metrics = array_fill_keys(['read_bw_MBps', 'write_bw_MBps', 'read_iops', 'write_iops', 'read_p95_ms', 'write_p95_ms'], 0.0);
    foreach ($totals as $direction => $total) {
        $sum = array_sum($total['p95']);
        if (!is_finite($total['iops']) || !is_finite((float) $sum)) return $invalid;
        $metrics[$direction.'_bw_MBps'] = round($total['bw'] / (1024 * 1024), 2);
        $metrics[$direction.'_iops'] = round($total['iops'], 1);
        $metrics[$direction.'_p95_ms'] = round((count($total['p95']) ? $sum / count($total['p95']) : 0) / 1000000, 2);
    }
    return ['ok' => true, 'result' => $metrics + ['raw' => $payload]];
}
