<?php
/**
 * Formatting helpers for the resource CLI output.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

function pmssResourceFormatBytes(float $bytes): string
{
    if ($bytes >= 1024 * 1024 * 1024 * 1024) {
        $value = $bytes / 1024 / 1024 / 1024 / 1024;
        $unit = 'TiB';
    } elseif ($bytes >= 1024 * 1024 * 1024) {
        $value = $bytes / 1024 / 1024 / 1024;
        $unit = 'GiB';
    } elseif ($bytes >= 1024 * 1024) {
        $value = $bytes / 1024 / 1024;
        $unit = 'MiB';
    } else {
        $value = $bytes / 1024;
        $unit = 'KiB';
    }
    return number_format($value, 2).' '.$unit;
}

function pmssResourceFormatCpuHours(float $cpuNsec): string
{
    $hours = $cpuNsec / 1000000000 / 3600;
    return number_format($hours, 1).' hrs';
}

function pmssResourceFormatRamHours(float $ramHours): string
{
    $decimals = 2;
    if ($ramHours >= 100) {
        $decimals = 0;
    } elseif ($ramHours >= 10) {
        $decimals = 1;
    }
    return number_format($ramHours, $decimals).' GB-hrs';
}

function pmssShowResourcesPrintHeader(): void
{
    printf("%-14s %-12s %-12s %-11s %-14s %-9s %-6s\n", 'Username', 'IO Read/mo', 'IO Write/mo', 'CPU hrs/mo', 'RAM GB-hrs/mo', 'Mem Now', 'Procs');
}

function pmssShowResourcesPrintRow(string $user, array $row): void
{
    printf(
        "%-14s %-12s %-12s %-11s %-14s %-9s %-6s\n",
        $user,
        pmssResourceFormatBytes($row['io_read']['month']),
        pmssResourceFormatBytes($row['io_write']['month']),
        pmssResourceFormatCpuHours($row['cpu']['month']),
        pmssResourceFormatRamHours($row['ram_hours']['month']),
        pmssResourceFormatBytes($row['memory_current']),
        (string) round($row['tasks_current'])
    );
}

function pmssShowResourcesPrintTotals(array $totals): void
{
    printf("%-14s %-12s %-12s %-11s %-14s %-9s %-6s\n", '---', '---', '---', '---', '---', '---', '---');
    printf(
        "%-14s %-12s %-12s %-11s %-14s %-9s %-6s\n",
        'Total',
        pmssResourceFormatBytes($totals['io_read']['month']),
        pmssResourceFormatBytes($totals['io_write']['month']),
        pmssResourceFormatCpuHours($totals['cpu']['month']),
        pmssResourceFormatRamHours($totals['ram_hours']['month']),
        pmssResourceFormatBytes($totals['memory_current']),
        (string) round($totals['tasks_current'])
    );
}
