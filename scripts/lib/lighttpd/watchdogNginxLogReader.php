<?php
/**
 * Incremental nginx access-log reader for the lighttpd watchdog.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/userFileWrite.php';
require_once __DIR__.'/watchdogNginxLog.php';

/** Read only newly appended nginx lines and persist the recovery cursor/state. */
function pmssLighttpdWatchdogNginxActionsRead(string $logPath, string $statePath, array $usersByPort): array
{
    if (!is_file($logPath) || is_link($logPath) || !pmssPathTargetIsSafe($statePath, false, true)) {
        return array();
    }

    $handle = @fopen($logPath, 'rb');
    $logStat = is_resource($handle) ? @fstat($handle) : false;
    $pathStat = @lstat($logPath);
    if (!is_resource($handle) || !is_array($logStat) || !is_array($pathStat)
        || ($logStat['dev'] ?? null) !== ($pathStat['dev'] ?? null)
        || ($logStat['ino'] ?? null) !== ($pathStat['ino'] ?? null)
    ) {
        if (is_resource($handle)) {
            @fclose($handle);
        }
        return array();
    }

    $state = pmssJsonFileReadAssoc($statePath, true);
    $firstRun = !is_array($state) || !isset($state['offset'], $state['device'], $state['inode']);
    $sameFile = !$firstRun
        && (int) $state['device'] === (int) $logStat['dev']
        && (int) $state['inode'] === (int) $logStat['ino'];
    $offset = $sameFile ? max(0, (int) $state['offset']) : 0;
    if ($firstRun) {
        $offset = (int) ($logStat['size'] ?? 0);
        $state = array('users' => array());
    } elseif ($offset > (int) ($logStat['size'] ?? 0)) {
        $offset = 0;
    }
    if (@fseek($handle, $offset) !== 0) {
        @fclose($handle);
        return array();
    }

    // Retain at most a reset plus the final failure per port. This preserves
    // relevant event order without holding an unbounded log backlog.
    $eventsByPort = array();
    while (($line = @fgets($handle)) !== false) {
        if (substr($line, -1) !== "\n") {
            @fseek($handle, -strlen($line), SEEK_CUR);
            break;
        }
        $event = pmssLighttpdWatchdogNginxEventParse($line);
        if ($event === null) {
            continue;
        }
        $port = $event['port'];
        if ($event['outcome'] === 'healthy') {
            $eventsByPort[$port] = array($event);
        } elseif (isset($eventsByPort[$port][0]) && $eventsByPort[$port][0]['outcome'] === 'healthy') {
            $eventsByPort[$port] = array($eventsByPort[$port][0], $event);
        } else {
            $eventsByPort[$port] = array($event);
        }
    }
    $newOffset = @ftell($handle);
    @fclose($handle);

    $events = array();
    foreach ($eventsByPort as $portEvents) {
        $events = array_merge($events, $portEvents);
    }
    $advanced = pmssLighttpdWatchdogNginxStateAdvance($state, $events, $usersByPort);
    $newState = array(
        'device' => (int) $logStat['dev'],
        'inode' => (int) $logStat['ino'],
        'offset' => is_int($newOffset) ? $newOffset : (int) ($logStat['size'] ?? 0),
        'users' => $advanced['users'],
    );
    $encoded = pmssJsonEncodePrettyLine($newState);
    if (!is_string($encoded) || !pmssAtomicWriteFile($statePath, $encoded, 0600)) {
        return array();
    }

    return $firstRun ? array() : $advanced['actions'];
}
