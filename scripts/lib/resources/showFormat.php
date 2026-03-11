<?php
/**
 * Formatting helpers for the resource CLI output.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

function pmssResourceFormatBytes(float $bytes): string
{
    if ($bytes >= 1099511627776.0) {
        return number_format($bytes / 1099511627776.0, 2).' TiB';
    }
    if ($bytes >= 1073741824.0) {
        return number_format($bytes / 1073741824.0, 2).' GiB';
    }
    if ($bytes >= 1048576.0) {
        return number_format($bytes / 1048576.0, 2).' MiB';
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
