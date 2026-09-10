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
    private $compareTimes;
    private $windowTotals;
    private $dailyTotals = [];
    private $firstDay = '';
    private $currentValues = ['memory' => 0.0, 'tasks' => 0.0, 'memory_anon' => null, 'memory_file' => null];
    private $prevTimestamp = null;

    public function __construct(array $compareTimes)
    {
        $this->compareTimes = $compareTimes;
        $this->windowTotals = array_fill_keys(array_keys($compareTimes), []);
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
            $this->addTotals($this->windowTotals[$label], $sampleMetrics + $sampleAverages);
        }

        $currentDay = date('Y/m/d', $timestamp);
        $this->firstDay = ($this->firstDay === '') ? $currentDay : $this->firstDay;
        if ($currentDay === $this->firstDay) return;
        $this->dailyTotals[$currentDay] = $this->dailyTotals[$currentDay] ?? [];
        $this->addTotals($this->dailyTotals[$currentDay], $sampleMetrics + $sampleAverages);
    }

    /** Every accepted sample contributes both averages, so one count serves the whole bucket. */
    private function addTotals(array &$totals, array $metrics): void
    {
        foreach ($metrics as $metric => $value) $totals[$metric] = ($totals[$metric] ?? 0.0) + $value;
        $totals['samples'] = ($totals['samples'] ?? 0) + 1;
    }

    /** Use the same sum/average calculation for rolling windows and complete days. */
    private function totalsResult(array $totals): array
    {
        $values = array_intersect_key($totals, array_flip(self::RAW_METRICS)) + array_fill_keys(self::RAW_METRICS, 0.0);
        foreach (self::AVERAGE_METRICS as $metric) {
            $values[$metric] = ($totals['samples'] ?? 0) > 0 ? $totals[$metric] / $totals['samples'] : 0.0;
        }
        return $values;
    }

    /**
     * Return true when at least one sample was added.
     */
    public function hasSamples(): bool { return $this->prevTimestamp !== null; }

    /**
     * Return the persisted payload consumed by resource reports and daily snapshots.
     */
    public function results(): array
    {
        $data = ['daily' => array_map([$this, 'totalsResult'], $this->dailyTotals)]
            + array_fill_keys(array_merge(self::RAW_METRICS, self::AVERAGE_METRICS), ['raw' => []]);
        foreach ($this->windowTotals as $label => $totals) {
            foreach ($this->totalsResult($totals) as $metric => $value) $data[$metric]['raw'][$label] = $value;
        }
        $data['memory']['current'] = $this->currentValues['memory'];
        // Missing breakdown samples retain the last valid values; never serialize null placeholders.
        foreach (['anon', 'file'] as $field) {
            if ($this->currentValues['memory_'.$field] !== null) $data['memory'][$field] = $this->currentValues['memory_'.$field];
        }
        $data['tasks']['current'] = $this->currentValues['tasks'];
        return $data;
    }
}
