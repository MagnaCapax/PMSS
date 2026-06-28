<?php
/**
 * Accumulates resource statistics for rolling windows.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

class ResourceStatsAccumulator
{
    public const RAW_METRICS = ['io_read', 'io_write', 'io_read_ops', 'io_write_ops', 'cpu', 'ram_hours'];
    public const AVERAGE_METRICS = ['memory', 'tasks'];
    private const CURRENT_RESULT_KEYS = ['memory' => 'current_memory', 'tasks' => 'current_tasks', 'memory_anon' => 'current_memory_anon', 'memory_file' => 'current_memory_file'];

    private $compareTimes;
    private $rawTotals;
    private $averageSums;
    private $averageCounts;
    private $dailyTotals = [];
    private $firstDay = '';
    private $currentValues = ['memory' => 0.0, 'tasks' => 0.0, 'memory_anon' => null, 'memory_file' => null];
    private $prevTimestamp = null;

    public function __construct(array $compareTimes)
    {
        $this->compareTimes = $compareTimes;
        $labels = array_keys($compareTimes);
        $windowZeros = array_fill_keys($labels, 0.0);
        $windowCounts = array_fill_keys($labels, 0);
        $this->rawTotals = array_fill_keys(self::RAW_METRICS, $windowZeros);
        foreach (self::AVERAGE_METRICS as $metric) {
            $this->averageSums[$metric] = $windowZeros;
            $this->averageCounts[$metric] = $windowCounts;
        }
    }

    /**
     * Add a parsed sample to the accumulator.
     */
    public function addSample(array $sample): void
    {
        $timestamp = (int) $sample['timestamp'];
        $sampleAverages = ['memory' => (float) $sample['memory'], 'tasks' => (float) $sample['tasks']];
        foreach ($sampleAverages as $metric => $value) $this->currentValues[$metric] = $value;
        foreach (['memory_anon', 'memory_file'] as $metric) if (isset($sample[$metric]) && is_numeric($sample[$metric])) $this->currentValues[$metric] = (float) $sample[$metric];

        $delta = ($this->prevTimestamp === null) ? 300 : ($timestamp - $this->prevTimestamp);
        $intervalHours = (($delta > 0 && $delta <= 3600) ? $delta : 300) / 3600;
        $this->prevTimestamp = $timestamp;
        $sampleMetrics = [
            'io_read' => (float) $sample['io_read'],
            'io_write' => (float) $sample['io_write'],
            'io_read_ops' => (float) ($sample['io_read_ops'] ?? 0.0),
            'io_write_ops' => (float) ($sample['io_write_ops'] ?? 0.0),
            'cpu' => (float) $sample['cpu'],
            'ram_hours' => ($sampleAverages['memory'] / 1024 / 1024 / 1024) * $intervalHours,
        ];

        foreach ($this->compareTimes as $label => $threshold) {
            if ($timestamp < $threshold) {
                continue;
            }
            foreach ($sampleMetrics as $metric => $value) {
                $this->rawTotals[$metric][$label] += $value;
            }
            foreach ($sampleAverages as $metric => $value) {
                $this->averageSums[$metric][$label] += $value;
                $this->averageCounts[$metric][$label] += 1;
            }
        }

        $currentDay = date('Y/m/d', $timestamp);
        $this->firstDay = ($this->firstDay === '') ? $currentDay : $this->firstDay;
        if ($currentDay === $this->firstDay) return;
        $this->dailyTotals[$currentDay] = $this->dailyTotals[$currentDay] ?? array_fill_keys(self::RAW_METRICS, 0.0)
            + array_fill_keys(['memory_sum', 'tasks_sum'], 0.0)
            + array_fill_keys(['memory_count', 'tasks_count'], 0);

        $dayTotals = &$this->dailyTotals[$currentDay];
        foreach ($sampleMetrics as $metric => $value) {
            $dayTotals[$metric] += $value;
        }
        foreach ($sampleAverages as $metric => $value) {
            $dayTotals[$metric.'_sum'] += $value;
            $dayTotals[$metric.'_count'] += 1;
        }
    }

    /**
     * Return true when at least one sample was added.
     */
    public function hasSamples(): bool { return $this->prevTimestamp !== null; }

    /**
     * Return computed totals and averages.
     */
    public function results(): array
    {
        $daily = [];
        $metricKeys = array_flip(self::RAW_METRICS);
        foreach ($this->dailyTotals as $day => $totals) {
            $daily[$day] = array_intersect_key($totals, $metricKeys);
            foreach (self::AVERAGE_METRICS as $metric) {
                $count = (int) ($totals[$metric.'_count'] ?? 0);
                $daily[$day][$metric] = $count > 0 ? ($totals[$metric.'_sum'] / $count) : 0.0;
            }
        }

        $result = [
            'raw' => $this->rawTotals,
            'memory' => $this->computeAverages($this->averageSums['memory'], $this->averageCounts['memory']),
            'tasks' => $this->computeAverages($this->averageSums['tasks'], $this->averageCounts['tasks']),
            'daily' => $daily,
        ];
        foreach (self::CURRENT_RESULT_KEYS as $field => $key) $result[$key] = $this->currentValues[$field];
        return $result;
    }

    private function computeAverages(array $sums, array $counts): array
    {
        $averages = [];
        foreach ($sums as $label => $sum) { $count = (int) ($counts[$label] ?? 0); $averages[$label] = $count > 0 ? ($sum / $count) : 0.0; }
        return $averages;
    }
}
