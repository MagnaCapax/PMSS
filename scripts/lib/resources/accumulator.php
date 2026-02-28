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
    private $lastMemory;
    /** @var float */
    private $lastTasks;
    /** @var int|null */
    private $prevTimestamp;
    /** @var int */
    private $sampleCount;

    public function __construct(array $compareTimes)
    {
        $this->compareTimes = $compareTimes;
        $labels = array_keys($compareTimes);
        $this->rawTotals = [
            'io_read'   => array_fill_keys($labels, 0.0),
            'io_write'  => array_fill_keys($labels, 0.0),
            'io_read_ops' => array_fill_keys($labels, 0.0),
            'io_write_ops' => array_fill_keys($labels, 0.0),
            'cpu'       => array_fill_keys($labels, 0.0),
            'ram_hours' => array_fill_keys($labels, 0.0),
        ];
        $this->memorySums = array_fill_keys($labels, 0.0);
        $this->memoryCounts = array_fill_keys($labels, 0);
        $this->taskSums = array_fill_keys($labels, 0.0);
        $this->taskCounts = array_fill_keys($labels, 0);
        $this->dailyAccumulator = new ResourceStatsDailyAccumulator();
        $this->lastMemory = 0.0;
        $this->lastTasks = 0.0;
        $this->prevTimestamp = null;
        $this->sampleCount = 0;
    }

    /**
     * Add a parsed sample to the accumulator.
     */
    public function addSample(array $sample): void
    {
        $this->sampleCount++;
        $timestamp = (int) $sample['timestamp'];
        $this->lastMemory = (float) $sample['memory'];
        $this->lastTasks = (float) $sample['tasks'];
        $sampleReadOps = isset($sample['io_read_ops']) ? (float) $sample['io_read_ops'] : 0.0;
        $sampleWriteOps = isset($sample['io_write_ops']) ? (float) $sample['io_write_ops'] : 0.0;

        $defaultIntervalHours = 300 / 3600;
        if ($this->prevTimestamp === null) {
            $intervalHours = $defaultIntervalHours;
        } else {
            $delta = $timestamp - $this->prevTimestamp;
            $intervalHours = ($delta > 0 && $delta <= 3600) ? ($delta / 3600) : $defaultIntervalHours;
        }
        $this->prevTimestamp = $timestamp;
        $sampleRamHours = ((float) $sample['memory'] / 1024 / 1024 / 1024) * $intervalHours;

        foreach ($this->compareTimes as $label => $threshold) {
            if ($timestamp >= $threshold) {
                $this->rawTotals['io_read'][$label] += $sample['io_read'];
                $this->rawTotals['io_write'][$label] += $sample['io_write'];
                $this->rawTotals['io_read_ops'][$label] += $sampleReadOps;
                $this->rawTotals['io_write_ops'][$label] += $sampleWriteOps;
                $this->rawTotals['cpu'][$label] += $sample['cpu'];
                $this->rawTotals['ram_hours'][$label] += $sampleRamHours;
                $this->memorySums[$label] += $sample['memory'];
                $this->memoryCounts[$label] += 1;
                $this->taskSums[$label] += $sample['tasks'];
                $this->taskCounts[$label] += 1;
            }
        }

        $this->dailyAccumulator->addSample($sample, $intervalHours);
    }

    /**
     * Return true when at least one sample was added.
     */
    public function hasSamples(): bool
    {
        return $this->sampleCount > 0;
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
            $count = isset($counts[$label]) ? (int) $counts[$label] : 0;
            $averages[$label] = $count > 0 ? ($sum / $count) : 0.0;
        }
        return $averages;
    }

    // Daily totals are handled by ResourceStatsDailyAccumulator.
}
