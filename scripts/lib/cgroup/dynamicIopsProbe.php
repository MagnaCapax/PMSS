<?php
/** Pure, persistent hourly HDD A/B work accounting (ADR 0238). @license GPL-3.0-only */

/** Offset clock-aligned block boundaries so neighboring guests need not flip together. */
function pmssDynamicIopsProbePhase(string $hostname, int $cycles): int
{
    return (int) (sprintf('%u', crc32($hostname)) % $cycles);
}

/** Accept complete state only; a corrupt or incompatible record starts a fresh experiment. */
function pmssDynamicIopsProbeState(?array $state, array $settings, int $start): array
{
    $valid = $state !== null && is_numeric($state['lo'] ?? null) && is_finite((float) $state['lo'])
        && $state['lo'] >= $settings['busyMultiplier'] && $state['lo'] <= $settings['idleMultiplier'] / 2
        && in_array($state['arm'] ?? null, ['lo', 'hi'], true)
        && is_int($state['block_start'] ?? null) && $state['block_start'] <= $start
        && ($start - $state['block_start']) % ($settings['probeBlockCycles'] * 300) === 0
        && is_int($state['block_samples'] ?? null)
        && $state['block_samples'] >= 0 && $state['block_samples'] <= $settings['probeBlockCycles']
        && is_array($state['last_counts'] ?? null) && is_array($state['pairs'] ?? null)
        && count($state['pairs']) < $settings['probePairs'] && is_int($state['last_time'] ?? null);
    foreach (['block_work', 'pending_work'] as $key) {
        $valid = $valid && array_key_exists($key, $state ?? [])
            && ($state[$key] === null || (is_numeric($state[$key]) && is_finite((float) $state[$key]) && $state[$key] >= 0));
    }
    foreach (is_array($state['pairs'] ?? null) ? $state['pairs'] : [] as $ratio) $valid = $valid && is_numeric($ratio) && is_finite((float) $ratio);
    foreach (is_array($state['last_counts'] ?? null) ? $state['last_counts'] : [] as $count) $valid = $valid && ($count === null || (is_int($count) && $count >= 0));
    return $valid ? $state : ['lo' => $settings['probeStartLo'], 'arm' => 'lo', 'block_start' => $start,
        'block_work' => null, 'block_samples' => 0, 'last_counts' => [], 'pairs' => [],
        'pending_work' => null, 'last_time' => 0];
}

/** Sum only counters present at both ends; resets or no comparable slices invalidate the cycle. */
function pmssDynamicIopsProbeWork(array $before, array $after): ?float
{
    $work = 0.0; $matched = false;
    foreach ($after as $uid => $count) {
        if (!is_int($count) || !is_int($before[$uid] ?? null)) continue;
        if ($count < $before[$uid]) return null;
        $matched = true;
        $work += $count - $before[$uid];
    }
    return $matched && is_finite($work) ? $work : null;
}

/**
 * Consume one observation and return the next arm's multiplier, state and log fields.
 * Null block_work marks a discarded block. last_time detects real elapsed gaps;
 * block_samples counts observations including the skipped first (lag) cycle.
 * Callers own I/O and must pass eligible=false whenever caps cannot apply.
 */
function pmssDynamicIopsProbeCycle(?array $saved, array $settings, array $counts, int $now,
    string $hostname, bool $mdSync, bool $eligible = true): array
{
    $cycles = $settings['probeBlockCycles']; $seconds = $cycles * 300;
    $phase = pmssDynamicIopsProbePhase($hostname, $cycles) * 300;
    $start = intdiv($now - $phase, $seconds) * $seconds + $phase;
    $state = pmssDynamicIopsProbeState($saved, $settings, $start);
    $elapsed = $now - $state['last_time'];
    $work = pmssDynamicIopsProbeWork($state['last_counts'], $counts);
    $valid = $eligible && !$mdSync && $elapsed >= 240 && $elapsed <= 420 && $work !== null;
    $ratio = $state['pairs'] === [] ? null : end($state['pairs']); $decision = null;
    if ($start !== $state['block_start']) {
        $blocks = intdiv($start - $state['block_start'], $seconds);
        // A missing cycle, restart or backwards clock cannot finish a valid pair.
        $complete = $blocks === 1 && $valid && $state['block_samples'] === $cycles && $state['block_work'] !== null;
        // Every adjacent valid pair counts, lo->hi and hi->lo alike, so a steady demand trend cancels.
        if ($complete && $state['pending_work'] > 0 && $state['block_work'] > 0) {
            $hiBlock = $state['arm'] === 'hi';
            $ratio = log(($hiBlock ? $state['block_work'] : $state['pending_work'])
                / ($hiBlock ? $state['pending_work'] : $state['block_work']));
            $state['pairs'][] = $ratio;
            if (count($state['pairs']) >= $settings['probePairs']) {
                $up = array_sum($state['pairs']) / count($state['pairs']) > log(1 + $settings['probeGain']);
                $state['lo'] = max($settings['busyMultiplier'], min($settings['idleMultiplier'] / 2,
                    $state['lo'] * ($up ? sqrt(2) : 1 / sqrt(2))));
                $decision = $up ? 'raise' : 'lower';
                $state['pairs'] = [];
            }
        }
        // A block measured before a decision ran at the old L and cannot pair with the next one.
        $state['pending_work'] = $complete && $decision === null ? $state['block_work'] : null;
        if (abs($blocks) % 2 === 1) $state['arm'] = $state['arm'] === 'lo' ? 'hi' : 'lo';
        $state['block_start'] = $start;
        $state['block_work'] = 0.0;
        $state['block_samples'] = 0;
    }
    $cycle = intdiv($now - $start, 300);
    // The first observation belongs to the previous arm; discard its delta for lag.
    if (!$valid || $cycle !== $state['block_samples']) $state['block_work'] = null;
    elseif ($cycle > 0 && $state['block_work'] !== null) $state['block_work'] += $work;
    $state['block_samples'] = $cycle + 1;
    $state['last_counts'] = $counts;
    $state['last_time'] = $now;
    $hi = min($settings['idleMultiplier'], 2 * $state['lo']);
    return ['state' => $state, 'multiplier' => $state['arm'] === 'lo' ? $state['lo'] : $hi,
        'probe' => ['lo' => $state['lo'], 'hi' => $hi, 'arm' => $state['arm'], 'block_cycle' => $cycle,
            'pairs_n' => count($state['pairs']), 'last_ratio' => $ratio, 'decision' => $decision]];
}
