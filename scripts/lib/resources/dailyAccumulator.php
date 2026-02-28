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
            $this->dailyTotals[$currentDay] = [
                'io_read'      => 0.0,
                'io_write'     => 0.0,
                'io_read_ops'  => 0.0,
                'io_write_ops' => 0.0,
                'cpu'          => 0.0,
                'ram_hours'    => 0.0,
                'memory_sum'   => 0.0,
                'memory_count' => 0,
                'tasks_sum'    => 0.0,
                'tasks_count'  => 0,
            ];
        }
        $sampleRamHours = ((float) $sample['memory'] / 1024 / 1024 / 1024) * $intervalHours;
        $this->dailyTotals[$currentDay]['io_read'] += $sample['io_read'];
        $this->dailyTotals[$currentDay]['io_write'] += $sample['io_write'];
        $this->dailyTotals[$currentDay]['io_read_ops'] += isset($sample['io_read_ops']) ? (float) $sample['io_read_ops'] : 0.0;
        $this->dailyTotals[$currentDay]['io_write_ops'] += isset($sample['io_write_ops']) ? (float) $sample['io_write_ops'] : 0.0;
        $this->dailyTotals[$currentDay]['cpu'] += $sample['cpu'];
        $this->dailyTotals[$currentDay]['ram_hours'] += $sampleRamHours;
        $this->dailyTotals[$currentDay]['memory_sum'] += $sample['memory'];
        $this->dailyTotals[$currentDay]['memory_count'] += 1;
        $this->dailyTotals[$currentDay]['tasks_sum'] += $sample['tasks'];
        $this->dailyTotals[$currentDay]['tasks_count'] += 1;
    }

    /**
     * Build the daily totals with memory/task averages.
     */
    public function results(): array
    {
        $daily = [];
        foreach ($this->dailyTotals as $day => $totals) {
            $memoryAvg = $totals['memory_count'] > 0 ? ($totals['memory_sum'] / $totals['memory_count']) : 0.0;
            $taskAvg = $totals['tasks_count'] > 0 ? ($totals['tasks_sum'] / $totals['tasks_count']) : 0.0;
            $daily[$day] = [
                'io_read'   => $totals['io_read'],
                'io_write'  => $totals['io_write'],
                'io_read_ops' => $totals['io_read_ops'],
                'io_write_ops' => $totals['io_write_ops'],
                'cpu'       => $totals['cpu'],
                'ram_hours' => $totals['ram_hours'],
                'memory'    => $memoryAvg,
                'tasks'     => $taskAvg,
            ];
        }
        return $daily;
    }
}
