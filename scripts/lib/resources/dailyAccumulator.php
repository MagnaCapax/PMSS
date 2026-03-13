<?php
/**
 * Aggregates daily resource totals for charting.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

class ResourceStatsDailyAccumulator
{
    private const RAW_METRICS = ['io_read', 'io_write', 'io_read_ops', 'io_write_ops', 'cpu', 'ram_hours'];

    /** @var array */
    private $dailyTotals;
    /** @var string */
    private $firstDay;

    public function __construct()
    {
        $this->dailyTotals = [];
        $this->firstDay = '';
    }

    /**
     * Add a parsed sample to the daily accumulator.
     */
    public function addSample(array $sample, float $intervalHours): void
    {
        $currentDay = date('Y/m/d', (int) $sample['timestamp']);
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
        foreach (
            [
                'io_read' => (float) $sample['io_read'],
                'io_write' => (float) $sample['io_write'],
                'io_read_ops' => (float) ($sample['io_read_ops'] ?? 0.0),
                'io_write_ops' => (float) ($sample['io_write_ops'] ?? 0.0),
                'cpu' => (float) $sample['cpu'],
                'ram_hours' => ((float) $sample['memory'] / 1024 / 1024 / 1024) * $intervalHours,
            ] as $metric => $value
        ) {
            $dayTotals[$metric] += $value;
        }
        $dayTotals['memory_sum'] += $sample['memory'];
        $dayTotals['memory_count'] += 1;
        $dayTotals['tasks_sum'] += $sample['tasks'];
        $dayTotals['tasks_count'] += 1;
    }

    /**
     * Build the daily totals with memory/task averages.
     */
    public function results(): array
    {
        $daily = [];
        $metricKeys = array_flip(self::RAW_METRICS);
        foreach ($this->dailyTotals as $day => $totals) {
            $daily[$day] = array_intersect_key($totals, $metricKeys) + [
                'memory'    => $totals['memory_count'] > 0 ? ($totals['memory_sum'] / $totals['memory_count']) : 0.0,
                'tasks'     => $totals['tasks_count'] > 0 ? ($totals['tasks_sum'] / $totals['tasks_count']) : 0.0,
            ];
        }
        return $daily;
    }
}
