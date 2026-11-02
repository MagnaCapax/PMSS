<?php
/** Iostat supplemental probes and ordered snapshot assembly. @license GPL-3.0-only */
require_once dirname(__DIR__).'/systemStats.php';
require_once __DIR__.'/sample.php';

/**
 * Read node-level I/O pressure (PSI) full avg300 from /proc/pressure/io.
 *
 * full_avg300 = percentage of the last 300s that ALL non-idle tasks were stalled
 * on I/O — the sustained saturation signal consumed by hallinta's oversale gate.
 * Returns null when PSI is unavailable (kernel <4.20 / CONFIG_PSI=n) so the brain
 * treats it as "no signal" and falls back to its other gates (fail-safe: a missing
 * value must never gate provisioning closed).
 *
 * @return float|null
 */
function pmssDiskIostatReadPsiFullAvg300(string $psiPath = '/proc/pressure/io'): ?float
{
    $raw = is_readable($psiPath) ? @file_get_contents($psiPath) : false;
    // Share validation while retaining this consumer's unrounded scalar result.
    return is_string($raw) ? pmssSystemStatsPsiMetricFromRaw($raw, 'full', 'avg300') : null;
}

/**
 * Read median ioping latency to /home in milliseconds for hallinta's I/O-health gate.
 *
 * Reuses the canonical pmssSystemStatsIopingMs() probe (DRY) rather than parsing it
 * positionally out of system-stats.log (which would reintroduce the column-shift
 * fragility class). Returns null when ioping is unavailable or the probe yields 'na'
 * so the brain treats a missing measurement as no-signal (fail-safe — never gates
 * provisioning on an absent value, in EITHER direction).
 *
 * @return float|null
 */
function pmssDiskIostatReadIopingHomeMs(): ?float
{
    $raw = pmssSystemStatsIopingMs('/home');           // e.g. "0.2ms" or "na"
    if (!is_string($raw) || !preg_match('/\A([0-9]+(?:\.[0-9]+)?)ms\z/', $raw, $matches)) {
        return null;
    }
    return pmssFiniteFloat($matches[1]);
}

/** Append host probes and metadata to the parsed fields in legacy serialization order. */
function pmssDiskIostatParseLatestSample(string $iostatRaw, int $deviceCount, ?int $timestamp = null): array
{
    return pmssDiskIostatParseSampleFields($iostatRaw) + [
        'psiFullAvg300' => pmssDiskIostatReadPsiFullAvg300(),
        'psiMemFullAvg300' => pmssDiskIostatReadPsiFullAvg300('/proc/pressure/memory'),
        'psiCpuFullAvg300' => pmssDiskIostatReadPsiFullAvg300('/proc/pressure/cpu'),
        'iopingHomeMs' => pmssDiskIostatReadIopingHomeMs(),
        'diskQuantity' => $deviceCount, 'time' => $timestamp ?? time(),
    ];
}
