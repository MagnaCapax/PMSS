<?php
/** Pure host-pool steering, loaded by dynamicIopsAllocation.php. @license GPL-3.0-only */

/** Build the ordered allocation record from validated settings before account facts and variants. */
function pmssDynamicIopsPool(array $settings, array $pressure, ?float $passive, ?float $benchmark): ?array
{
    $blend = pmssFiniteNonNegativeFloat($pressure['blend'] ?? null);
    if ($blend !== null && $blend > 100) $blend = null;
    $fraction = $blend === null ? 1.0 : max(0.0, min(1.0,
        ($blend - $settings['idlePressure']) / ($settings['busyPressure'] - $settings['idlePressure'])));
    $multiplier = $settings['idleMultiplier'] + $fraction * ($settings['busyMultiplier'] - $settings['idleMultiplier']);
    // Only solid state uses a latency band (ADR 0237); HDDs use paired work blocks (ADR 0238).
    $latency = pmssFiniteNonNegativeFloat($pressure['latency_ms'] ?? null);
    $media = $pressure['media'] ?? null;
    $reference = pmssFiniteNonNegativeFloat($pressure['latency_reference_ms'] ?? null);
    $band = in_array($media, ['ssd', 'nvme'], true) && $reference !== null && $reference > 0
        ? [$settings['ssdLatencyLowX'] * $reference, $settings['ssdLatencyHighX'] * $reference] : null;
    $steer = $media === 'hdd' ? 'probe' : ($latency !== null && $band !== null ? 'latency' : 'pressure');
    if ($steer === 'latency') {
        $previous = pmssFiniteNonNegativeFloat($pressure['previous_multiplier'] ?? null) ?? $multiplier;
        $next = $latency < $band[0] ? $previous * $settings['latencyStep']
            : ($latency > $band[1] ? $previous / $settings['latencyStep'] : $previous);
        $multiplier = max($settings['busyMultiplier'], min($settings['idleMultiplier'], $next));
    }
    $multiplier = $steer === 'probe' ? ($pressure['probe_multiplier'] ?? $settings['probeStartLo']) : $multiplier;
    $passive = pmssFiniteNonNegativeFloat($passive);
    $benchmark = pmssFiniteNonNegativeFloat($benchmark);
    // A cached fio figure can exceed real disk IOPS a thousandfold; a cap needs the measured ceiling.
    $host = $passive ?? 0.0;
    $pool = $host * $multiplier;
    if (!is_finite($pool) || $pool >= PHP_INT_MAX) return null;
    $iowait = pmssFiniteNonNegativeFloat($pressure['iowait_blend'] ?? null);
    if ($iowait !== null && $iowait > 100) $iowait = null;
    $curb = $iowait === null ? 1.0 : 1 - $settings['curbMaxCut'] * max(0.0, min(1.0,
        ($iowait - $settings['curbStartIowait']) / ($settings['curbFullIowait'] - $settings['curbStartIowait'])));
    $result = ['pressure' => $pressure, 'passive_read_iops' => $passive, 'benchmark_read_iops' => $benchmark,
        'host_read_iops' => $host, 'multiplier' => $multiplier, 'steer' => $steer, 'pool' => $pool * $curb,
        'fallback' => $host <= 0 ? 'missing-ceiling' : ($blend === null && $steer !== 'probe' ? 'missing-pressure' : null), 'variants' => []];
    return $result + ['iowait_blend' => $iowait, 'curb_factor' => $curb, 'pool_uncurbed' => $pool];
}
