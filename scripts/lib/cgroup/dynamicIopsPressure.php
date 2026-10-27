<?php
/** Pure five-minute pressure windows for shadow IOPS. @license GPL-3.0-only */
require_once dirname(__DIR__).'/runtime.php';

/** Require distinct, recent five-minute buckets; duplicates cannot fill gaps. */
function pmssDynamicIopsPressure(iterable $samples, int $now): array
{
    $rows = [];
    foreach ($samples as $sample) {
        if (!is_array($sample) || !is_int($sample['time'] ?? null)
            || $sample['time'] > $now || $sample['time'] <= $now - 3600) continue;
        $bucket = intdiv($now - $sample['time'], 300);
        if (!isset($rows[$bucket]) || $sample['time'] > $rows[$bucket]['time']) $rows[$bucket] = $sample;
    }
    $pressure = [];
    foreach (['io' => 'psiFullAvg300', 'memory' => 'psiMemFullAvg300', 'cpu' => 'psiCpuFullAvg300'] as $kind => $field) {
        foreach ([5 => 1, 15 => 3, 60 => 12] as $minutes => $count) {
            $sum = 0.0;
            for ($n = 0; $n < $count; $n++) {
                $value = pmssFiniteNonNegativeFloat($rows[$n][$field] ?? null);
                if ($value === null || $value > 100) break;
                $sum += $value;
            }
            $pressure[$kind][$minutes] = $n === $count ? $sum / $count : null;
        }
    }
    $io = $pressure['io'];
    $pressure['blend'] = in_array(null, $io, true) ? null : (12 * $io[5] + 4 * $io[15] + $io[60]) / 17;
    return $pressure;
}
