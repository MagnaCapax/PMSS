<?php
/**
 * Formatting helpers for the resource CLI output.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

function pmssResourceFormatBytes(float $bytes): string
{
    $steps = [
        [1024 * 1024 * 1024 * 1024, 'TiB'],
        [1024 * 1024 * 1024, 'GiB'],
        [1024 * 1024, 'MiB'],
    ];
    foreach ($steps as $step) {
        $threshold = (float) $step[0];
        if ($bytes >= $threshold) {
            return number_format($bytes / $threshold, 2).' '.$step[1];
        }
    }

    return number_format($bytes / 1024, 2).' KiB';
}

function pmssResourceFormatCpuHours(float $cpuNsec): string
{
    $hours = $cpuNsec / 1000000000 / 3600;
    return number_format($hours, 1).' hrs';
}

function pmssResourceFormatRamHours(float $ramHours): string
{
    $decimals = $ramHours >= 100 ? 0 : ($ramHours >= 10 ? 1 : 2);
    return number_format($ramHours, $decimals).' GB-hrs';
}

function pmssResourceFormatOpsPerSecond(float $ops, int $windowSeconds): string
{
    if ($windowSeconds <= 0) {
        return '0.00';
    }
    return number_format($ops / $windowSeconds, 2);
}
