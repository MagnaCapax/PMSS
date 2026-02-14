<?php
/**
 * Data helpers for resource snapshot collection.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../resources.php';
require_once __DIR__.'/accumulator.php';

function pmssResourceSnapshotReadUserData(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $data = @unserialize($raw);
    return is_array($data) ? $data : null;
}

function pmssResourceSnapshotExtractDay(array $data): ?array
{
    $ioRead = $data['io_read']['raw']['day'] ?? null;
    $ioWrite = $data['io_write']['raw']['day'] ?? null;
    $cpu = $data['cpu']['raw']['day'] ?? null;
    $memory = $data['memory']['raw']['day'] ?? null;
    $ramHours = $data['ram_hours']['raw']['day'] ?? null;
    $tasks = $data['tasks']['raw']['day'] ?? null;

    if ($ioRead === null || $ioWrite === null || $cpu === null || $memory === null || $ramHours === null || $tasks === null) {
        return null;
    }

    return [
        'io_read' => (float) $ioRead,
        'io_write' => (float) $ioWrite,
        'cpu' => (float) $cpu,
        'memory' => (float) $memory,
        'ram_hours' => (float) $ramHours,
        'tasks' => (float) $tasks,
    ];
}

function pmssResourceSnapshotComputeFromLog(resourceStatistics $stats, string $user): ?array
{
    $dataLines = $stats->getData($user, 350);
    if (trim($dataLines) === '') {
        return null;
    }

    $threshold = time() - (24 * 60 * 60);
    $lines = array_filter(explode("\n", trim($dataLines)));
    $compareTimes = ['day' => $threshold];
    $accumulator = new ResourceStatsAccumulator($compareTimes);

    foreach ($lines as $line) {
        $parsed = $stats->parseLine($line);
        if ($parsed === false || $parsed['timestamp'] < $threshold) {
            continue;
        }
        $accumulator->addSample($parsed);
    }

    if (!$accumulator->hasSamples()) {
        return null;
    }

    $results = $accumulator->results();

    return [
        'io_read' => $results['raw']['io_read']['day'],
        'io_write' => $results['raw']['io_write']['day'],
        'cpu' => $results['raw']['cpu']['day'],
        'memory' => $results['memory']['day'],
        'ram_hours' => $results['raw']['ram_hours']['day'],
        'tasks' => $results['tasks']['day'],
    ];
}
