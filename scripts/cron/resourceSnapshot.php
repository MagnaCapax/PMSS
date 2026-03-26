#!/usr/bin/env php
<?php
/**
 * Cron task: resource snapshot.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/resources/log.php';
require_once __DIR__.'/../lib/resources.php';
require_once __DIR__.'/../lib/resources/accumulator.php';
require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/userLifecycle.php';

const PMSS_RESOURCE_SNAPSHOT_LOG_DEFAULT = '/var/log/pmss/resource-daily.log';

/**
 * Capture and persist one snapshot.
 *
 * @return int exit code
 */
function pmssResourceSnapshotRun(): int
{
    $logPath = getenv('PMSS_RESOURCE_SNAPSHOT_LOG') ?: PMSS_RESOURCE_SNAPSHOT_LOG_DEFAULT;
    $ts = date('Y-m-d\\TH:i:s');

    return pmssWithSnapshotLog(__FILE__, $logPath, static function ($fh) use ($ts): int {
        $users = pmssListManagedUsers();
        if ($users === []) {
            return 0;
        }
        $users[] = 'www-data';

        $stats = new resourceStatistics();
        $homeDir = rtrim(getenv('PMSS_HOME_DIR') ?: '/home', '/');

        foreach ($users as $user) {
            if (!pmssResourceLogIsValidUser($user) || ($uid = pmssResourceLogLookupUid($user)) === null) {
                continue;
            }

            $dataPath = $homeDir.'/'.$user.'/.resourceData';
            $raw = is_file($dataPath) ? @file_get_contents($dataPath) : false;
            $metrics = null;
            if (is_string($raw) && trim($raw) !== '' && is_array($data = @unserialize($raw))) {
                $metrics = [];
                foreach (['io_read', 'io_write', 'cpu', 'memory', 'ram_hours', 'tasks'] as $key) {
                    $value = $data[$key]['raw']['day'] ?? null;
                    if ($value === null) {
                        $metrics = null;
                        break;
                    }
                    $metrics[$key] = (float) $value;
                }
                if ($metrics !== null) {
                    $metrics['io_read_ops'] = (float) ($data['io_read_ops']['raw']['day'] ?? 0.0);
                    $metrics['io_write_ops'] = (float) ($data['io_write_ops']['raw']['day'] ?? 0.0);
                }
            }

            if ($metrics === null && ($dataLines = trim($stats->getData($user, 350))) !== '') {
                $threshold = time() - (24 * 60 * 60);
                $accumulator = new ResourceStatsAccumulator(['day' => $threshold]);
                foreach (array_filter(explode("\n", $dataLines)) as $line) {
                    $parsed = $stats->parseLine($line);
                    if ($parsed === false || $parsed['timestamp'] < $threshold) {
                        continue;
                    }
                    $accumulator->addSample($parsed);
                }
                if ($accumulator->hasSamples()) {
                    $results = $accumulator->results();
                    $metrics = [
                        'memory' => $results['memory']['day'],
                        'tasks' => $results['tasks']['day'],
                    ];
                    foreach (['io_read', 'io_write', 'io_read_ops', 'io_write_ops', 'cpu', 'ram_hours'] as $metricName) {
                        $metrics[$metricName] = $results['raw'][$metricName]['day'];
                    }
                }
            }

            if ($metrics === null) {
                @fwrite($fh, $ts.' WARN resource_missing user='.$user.PHP_EOL);
                continue;
            }

            @fwrite(
                $fh,
                sprintf(
                    '%s %d %d %d %d %d %.4f %.2f %d %d',
                    $ts,
                    $uid,
                    (int) round($metrics['io_read']),
                    (int) round($metrics['io_write']),
                    (int) round($metrics['cpu']),
                    (int) round($metrics['memory']),
                    $metrics['ram_hours'],
                    $metrics['tasks'],
                    (int) round($metrics['io_read_ops']),
                    (int) round($metrics['io_write_ops'])
                ).PHP_EOL
            );
        }

        return 0;
    });
}

pmssRunCliEntrypoint(__FILE__, 'pmssResourceSnapshotRun');
