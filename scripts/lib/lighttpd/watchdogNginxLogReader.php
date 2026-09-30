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
    if (!pmssRegularFilePathIsReadable($logPath) || !pmssPathTargetIsSafe($statePath, false, true)) {
        return array();
    }

    $handle = @fopen($logPath, 'rb');
    try {
        $logStat = null;
        $pathStat = @lstat($logPath);
        if (!pmssStreamHandleIsOpen($handle) || !is_array($pathStat)
            || !pmssLockFileHandleMatchesPath($handle, $logPath, $pathStat, $logStat)
        ) {
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
            return array();
        }

        // Retain at most a reset plus the final failure per port. This preserves
        // relevant event order without holding an unbounded log backlog.
        $eventsByPort = array();
        $partialLine = false;
        while (($line = @fgets($handle)) !== false) {
            if (substr($line, -1) !== "\n") {
                // Preserve the incomplete line for the next run only if rewind succeeds.
                if (@fseek($handle, -strlen($line), SEEK_CUR) !== 0) {
                    return array();
                }
                $partialLine = true;
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
        // A read error is not EOF: do not publish a cursor past unprocessed lines.
        if (!$partialLine && !feof($handle)) {
            return array();
        }
        $newOffset = @ftell($handle);
        if (!is_int($newOffset)) {
            return array();
        }
    } finally {
        // Cover metadata, state loading, and parsing failures as well as early returns.
        if (pmssStreamHandleIsOpen($handle)) {
            @fclose($handle);
        }
    }

    $events = array();
    foreach ($eventsByPort as $portEvents) {
        $events = array_merge($events, $portEvents);
    }
    $advanced = pmssLighttpdWatchdogNginxStateAdvance($state, $events, $usersByPort);
    $newState = array(
        'device' => (int) $logStat['dev'],
        'inode' => (int) $logStat['ino'],
        'offset' => $newOffset,
        'users' => $advanced['users'],
    );
    if (!pmssAtomicJsonFileWrite($statePath, $newState, 0600)) {
        return array();
    }

    return $firstRun ? array() : $advanced['actions'];
}
