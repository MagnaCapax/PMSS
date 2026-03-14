<?php
/**
 * Accumulates resource statistics for rolling windows.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

class ResourceStatsAccumulator
{
    private const RAW_METRICS = ['io_read', 'io_write', 'io_read_ops', 'io_write_ops', 'cpu', 'ram_hours'];

    /** @var array */
    private $compareTimes;
    /** @var array */
    private $rawTotals;
    /** @var array */
    private $memorySums;
    /** @var array */
    private $memoryCounts;
    /** @var array */
    private $taskSums;
    /** @var array */
    private $taskCounts;
    /** @var array */
    private $dailyTotals = [];
    /** @var string */
    private $firstDay = '';
    /** @var float */
    private $lastMemory = 0.0;
    /** @var float */
    private $lastTasks = 0.0;
    /** @var int|null */
    private $prevTimestamp = null;

    public function __construct(array $compareTimes)
    {
        $this->compareTimes = $compareTimes;
        $labels = array_keys($compareTimes);
        $windowZeros = array_fill_keys($labels, 0.0);
        $this->rawTotals = array_fill_keys(self::RAW_METRICS, $windowZeros);
        $this->memorySums = $this->taskSums = $windowZeros;
        $this->memoryCounts = $this->taskCounts = array_fill_keys($labels, 0);
    }

    /**
     * Add a parsed sample to the accumulator.
     */
    public function addSample(array $sample): void
    {
        $timestamp = (int) $sample['timestamp'];
        $this->lastMemory = $sampleMemory = (float) $sample['memory'];
        $this->lastTasks = $sampleTasks = (float) $sample['tasks'];

        $delta = ($this->prevTimestamp === null) ? 300 : ($timestamp - $this->prevTimestamp);
        $intervalHours = (($delta > 0 && $delta <= 3600) ? $delta : 300) / 3600;
        $this->prevTimestamp = $timestamp;
        $sampleMetrics = [
            'io_read' => (float) $sample['io_read'],
            'io_write' => (float) $sample['io_write'],
            'io_read_ops' => (float) ($sample['io_read_ops'] ?? 0.0),
            'io_write_ops' => (float) ($sample['io_write_ops'] ?? 0.0),
            'cpu' => (float) $sample['cpu'],
            'ram_hours' => ($sampleMemory / 1024 / 1024 / 1024) * $intervalHours,
        ];

        foreach ($this->compareTimes as $label => $threshold) {
            if ($timestamp < $threshold) {
                continue;
            }
            foreach ($sampleMetrics as $metric => $value) {
                $this->rawTotals[$metric][$label] += $value;
            }
            $this->memorySums[$label] += $sampleMemory;
            $this->memoryCounts[$label] += 1;
            $this->taskSums[$label] += $sampleTasks;
            $this->taskCounts[$label] += 1;
        }

        $currentDay = date('Y/m/d', $timestamp);
        $this->firstDay = ($this->firstDay === '') ? $currentDay : $this->firstDay;
        if ($currentDay === $this->firstDay) {
            return;
        }
        if (!isset($this->dailyTotals[$currentDay])) {
            $this->dailyTotals[$currentDay] = array_fill_keys(
                array_merge(self::RAW_METRICS, ['memory_sum', 'tasks_sum']),
                0.0
            ) + ['memory_count' => 0, 'tasks_count' => 0];
        }

        $dayTotals = &$this->dailyTotals[$currentDay];
        foreach ($sampleMetrics as $metric => $value) {
            $dayTotals[$metric] += $value;
        }
        $dayTotals['memory_sum'] += $sampleMemory;
        $dayTotals['memory_count'] += 1;
        $dayTotals['tasks_sum'] += $sampleTasks;
        $dayTotals['tasks_count'] += 1;
    }

    /**
     * Return true when at least one sample was added.
     */
    public function hasSamples(): bool
    {
        return $this->prevTimestamp !== null;
    }

    /**
     * Return computed totals and averages.
     */
    public function results(): array
    {
        $daily = [];
        $metricKeys = array_flip(self::RAW_METRICS);
        foreach ($this->dailyTotals as $day => $totals) {
            $daily[$day] = array_intersect_key($totals, $metricKeys) + [
                'memory' => $totals['memory_count'] > 0 ? ($totals['memory_sum'] / $totals['memory_count']) : 0.0,
                'tasks' => $totals['tasks_count'] > 0 ? ($totals['tasks_sum'] / $totals['tasks_count']) : 0.0,
            ];
        }

        return [
            'raw' => $this->rawTotals,
            'memory' => $this->computeAverages($this->memorySums, $this->memoryCounts),
            'tasks' => $this->computeAverages($this->taskSums, $this->taskCounts),
            'daily' => $daily,
            'current_memory' => $this->lastMemory,
            'current_tasks' => $this->lastTasks,
        ];
    }

    private function computeAverages(array $sums, array $counts): array
    {
        $averages = [];
        foreach ($sums as $label => $sum) {
            $count = (int) ($counts[$label] ?? 0);
            $averages[$label] = $count > 0 ? ($sum / $count) : 0.0;
        }
        return $averages;
    }
}
