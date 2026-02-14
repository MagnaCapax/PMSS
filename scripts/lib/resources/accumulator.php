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
    private $windowLabels;
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
        $this->windowLabels = array_keys($compareTimes);
        $this->rawTotals = [
            'io_read'   => array_fill_keys($this->windowLabels, 0.0),
            'io_write'  => array_fill_keys($this->windowLabels, 0.0),
            'cpu'       => array_fill_keys($this->windowLabels, 0.0),
            'ram_hours' => array_fill_keys($this->windowLabels, 0.0),
        ];
        $this->memorySums = array_fill_keys($this->windowLabels, 0.0);
        $this->memoryCounts = array_fill_keys($this->windowLabels, 0);
        $this->taskSums = array_fill_keys($this->windowLabels, 0.0);
        $this->taskCounts = array_fill_keys($this->windowLabels, 0);
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

        $sampleHours = $this->sampleHours($timestamp);
        $this->prevTimestamp = $timestamp;

        foreach ($this->compareTimes as $label => $threshold) {
            if ($timestamp >= $threshold) {
                $this->rawTotals['io_read'][$label] += $sample['io_read'];
                $this->rawTotals['io_write'][$label] += $sample['io_write'];
                $this->rawTotals['cpu'][$label] += $sample['cpu'];
                $this->rawTotals['ram_hours'][$label] += $this->ramHoursSample($sample['memory'], $sampleHours);
                $this->memorySums[$label] += $sample['memory'];
                $this->memoryCounts[$label] += 1;
                $this->taskSums[$label] += $sample['tasks'];
                $this->taskCounts[$label] += 1;
            }
        }

        $this->dailyAccumulator->addSample($sample, $sampleHours);
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

    private function sampleHours(int $timestamp): float
    {
        $default = 300 / 3600;
        if ($this->prevTimestamp === null) {
            return $default;
        }
        $delta = $timestamp - $this->prevTimestamp;
        if ($delta <= 0 || $delta > 3600) {
            return $default;
        }
        return $delta / 3600;
    }

    private function ramHoursSample(float $memoryBytes, float $sampleHours): float
    {
        $gib = $memoryBytes / 1024 / 1024 / 1024;
        return $gib * $sampleHours;
    }

    // Daily totals are handled by ResourceStatsDailyAccumulator.
}
