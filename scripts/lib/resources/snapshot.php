<?php
/**
 * Daily snapshot helper for per-user resource usage.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/log.php';
require_once __DIR__.'/../resources.php';
require_once __DIR__.'/accumulator.php';

const PMSS_RESOURCE_SNAPSHOT_LOG_DEFAULT = '/var/log/pmss/resource-daily.log';

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

function pmssResourceSnapshotComputeFromLog(resourceStatistics $stats, string $user): ?array
{
    $dataLines = $stats->getData($user, 350);
    if (trim($dataLines) === '') {
        return null;
    }

    $threshold = time() - (24 * 60 * 60);
    $lines = array_filter(explode("\n", trim($dataLines)));
    $accumulator = new ResourceStatsAccumulator(['day' => $threshold]);

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

/**
 * Capture and persist one snapshot.
 *
 * @return int exit code
 */
function pmssResourceSnapshotRun(): int
{
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        fwrite(STDERR, "resourceSnapshot.php must be run as root.\n");
        return 1;
    }

    $logPath = getenv('PMSS_RESOURCE_SNAPSHOT_LOG') ?: PMSS_RESOURCE_SNAPSHOT_LOG_DEFAULT;
    $logDir = dirname($logPath);
    $ts = date('Y-m-d\\TH:i:s');

    $oldUmask = umask(0077);
    if (!is_dir($logDir) && !@mkdir($logDir, 0755, true) && !is_dir($logDir)) {
        umask($oldUmask);
        return 1;
    }

    $fh = @fopen($logPath, 'ab');
    if ($fh === false) {
        umask($oldUmask);
        return 1;
    }
    @chmod($logPath, 0600);
    if (function_exists('flock')) {
        @flock($fh, LOCK_EX);
    }

    $users = pmssResourceLogLoadUsers();
    if (empty($users)) {
        @fclose($fh);
        umask($oldUmask);
        return 0;
    }

    $stats = new resourceStatistics();
    $homeDir = rtrim(getenv('PMSS_HOME_DIR') ?: '/home', '/');

    foreach ($users as $user) {
        if (!pmssResourceLogIsValidUser($user)) {
            continue;
        }

        $uid = pmssResourceLogLookupUid($user);
        if ($uid === null) {
            continue;
        }

        $data = pmssResourceSnapshotReadUserData($homeDir.'/'.$user.'/.resourceData');
        $metrics = null;
        if ($data !== null) {
            $keys = ['io_read', 'io_write', 'cpu', 'memory', 'ram_hours', 'tasks'];
            $metrics = [];
            foreach ($keys as $key) {
                $value = $data[$key]['raw']['day'] ?? null;
                if ($value === null) {
                    $metrics = null;
                    break;
                }
                $metrics[$key] = (float) $value;
            }
        }

        if ($metrics === null) {
            $metrics = pmssResourceSnapshotComputeFromLog($stats, $user);
        }

        if ($metrics === null) {
            @fwrite($fh, $ts.' WARN resource_missing user='.$user.PHP_EOL);
            continue;
        }

        @fwrite(
            $fh,
            sprintf(
                '%s %d %d %d %d %d %.4f %.2f',
                $ts,
                $uid,
                (int) round($metrics['io_read']),
                (int) round($metrics['io_write']),
                (int) round($metrics['cpu']),
                (int) round($metrics['memory']),
                $metrics['ram_hours'],
                $metrics['tasks']
            ).PHP_EOL
        );
    }

    @fclose($fh);
    umask($oldUmask);
    return 0;
}
