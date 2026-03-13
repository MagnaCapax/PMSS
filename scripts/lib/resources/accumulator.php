<?php
/**
 * Accumulates resource statistics for rolling windows.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/dailyAccumulator.php';

class ResourceStatsAccumulator
{
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
    /** @var ResourceStatsDailyAccumulator */
    private $dailyAccumulator;
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
        $this->rawTotals = array_fill_keys(['io_read', 'io_write', 'io_read_ops', 'io_write_ops', 'cpu', 'ram_hours'], $windowZeros);
        $this->memorySums = $this->taskSums = $windowZeros;
        $this->memoryCounts = $this->taskCounts = array_fill_keys($labels, 0);
        $this->dailyAccumulator = new ResourceStatsDailyAccumulator();
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

        $this->dailyAccumulator->addSample($sample, $intervalHours);
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
        return [
            'raw' => $this->rawTotals,
            'memory' => $this->computeAverages($this->memorySums, $this->memoryCounts),
            'tasks' => $this->computeAverages($this->taskSums, $this->taskCounts),
            'daily' => $this->dailyAccumulator->results(),
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

    // Daily totals are handled by ResourceStatsDailyAccumulator.
}
