<?php
/**
 * Formatting helpers for resource statistics.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

class ResourceStatsFormatter
{
    /** Format bytes into human readable units for display. */
    public function formatBytesDisplay(array $rawTotals): array
    {
        $formatted = [];
        foreach ($rawTotals as $label => $value) {
            $bytes = (float) $value;
            if (($bytes / 1024 / 1024 / 1024 / 1024) > 1) {
                $formatted[$label] = round($bytes / 1024 / 1024 / 1024 / 1024, 2).'TiB';
            } elseif (($bytes / 1024 / 1024 / 1024) > 1) {
                $formatted[$label] = round($bytes / 1024 / 1024 / 1024, 2).'GiB';
            } elseif (($bytes / 1024 / 1024) > 1) {
                $formatted[$label] = round($bytes / 1024 / 1024, 2).'MiB';
            } else {
                $formatted[$label] = round($bytes / 1024, 2).'KiB';
            }
        }
        return $formatted;
    }

    /** Format CPU nanoseconds into a readable time string. */
    public function formatCpuDisplay(array $rawTotals): array
    {
        $formatted = [];
        foreach ($rawTotals as $label => $value) {
            $formatted[$label] = $this->formatDurationSeconds($value / 1000000000);
        }
        return $formatted;
    }

    /** Format numeric averages for display. */
    public function formatCountDisplay(array $rawTotals): array
    {
        $formatted = [];
        foreach ($rawTotals as $label => $value) {
            $formatted[$label] = (string) round($value, 2);
        }
        return $formatted;
    }

    /** Format RAM-hours values for display. */
    public function formatRamHoursDisplay(array $rawTotals): array
    {
        $formatted = [];
        foreach ($rawTotals as $label => $value) {
            $formatted[$label] = round($value, 2).'GB-hrs';
        }
        return $formatted;
    }

    private function formatDurationSeconds(float $seconds): string
    {
        if ($seconds >= 3600) {
            return round($seconds / 3600, 2).'h';
        }
        if ($seconds >= 60) {
            return round($seconds / 60, 2).'m';
        }
        return round($seconds, 2).'s';
    }
}
