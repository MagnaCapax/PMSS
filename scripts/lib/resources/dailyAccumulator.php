<?php
/**
 * Aggregates daily resource totals for charting.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

class ResourceStatsDailyAccumulator
{
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
        if ($this->firstDay === '') {
            $this->firstDay = $currentDay;
        }
        if ($currentDay === $this->firstDay) {
            return;
        }
        if (!isset($this->dailyTotals[$currentDay])) {
            $this->dailyTotals[$currentDay] = array_fill_keys(
                ['io_read', 'io_write', 'io_read_ops', 'io_write_ops', 'cpu', 'ram_hours', 'memory_sum', 'tasks_sum'],
                0.0
            ) + ['memory_count' => 0, 'tasks_count' => 0];
        }

        $dayTotals = &$this->dailyTotals[$currentDay];
        $sampleRamHours = ((float) $sample['memory'] / 1024 / 1024 / 1024) * $intervalHours;
        $dayTotals['io_read'] += $sample['io_read'];
        $dayTotals['io_write'] += $sample['io_write'];
        $dayTotals['io_read_ops'] += (float) ($sample['io_read_ops'] ?? 0.0);
        $dayTotals['io_write_ops'] += (float) ($sample['io_write_ops'] ?? 0.0);
        $dayTotals['cpu'] += $sample['cpu'];
        $dayTotals['ram_hours'] += $sampleRamHours;
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
        foreach ($this->dailyTotals as $day => $totals) {
            $daily[$day] = [
                'io_read'   => $totals['io_read'],
                'io_write'  => $totals['io_write'],
                'io_read_ops' => $totals['io_read_ops'],
                'io_write_ops' => $totals['io_write_ops'],
                'cpu'       => $totals['cpu'],
                'ram_hours' => $totals['ram_hours'],
                'memory'    => $totals['memory_count'] > 0 ? ($totals['memory_sum'] / $totals['memory_count']) : 0.0,
                'tasks'     => $totals['tasks_count'] > 0 ? ($totals['tasks_sum'] / $totals['tasks_count']) : 0.0,
            ];
        }
        return $daily;
    }
}
