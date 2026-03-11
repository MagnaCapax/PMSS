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
        [1099511627776.0, 'TiB'],
        [1073741824.0, 'GiB'],
        [1048576.0, 'MiB'],
    ];
    foreach ($steps as list($threshold, $unit)) {
        if ($bytes >= $threshold) {
            return number_format($bytes / $threshold, 2).' '.$unit;
        }
    }

    return number_format($bytes / 1024, 2).' KiB';
}

function pmssResourceFormatCpuHours(float $cpuNsec): string
{
    return number_format($cpuNsec / 1000000000 / 3600, 1).' hrs';
}

function pmssResourceFormatRamHours(float $ramHours): string
{
    return number_format($ramHours, $ramHours >= 100 ? 0 : ($ramHours >= 10 ? 1 : 2)).' GB-hrs';
}

function pmssResourceFormatOpsPerSecond(float $ops, int $windowSeconds): string
{
    return ($windowSeconds <= 0) ? '0.00' : number_format($ops / $windowSeconds, 2);
}
