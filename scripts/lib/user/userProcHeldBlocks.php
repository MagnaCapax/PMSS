<?php
/**
 * Aggregate quota blocks held by deleted files in one user's processes.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/userConfigRuntime.php';

/**
 * Sum blocks for deleted, account-owned descriptors on the home device.
 *
 * @param callable|null $statReader Optional stat reader for hermetic tests.
 */
function pmssUserProcHeldBlocks(
    int $uid,
    int $homeDevice,
    string $procRoot = '/proc',
    ?callable $statReader = null
): ?int {
    if ($uid < 1 || $homeDevice < 0 || $procRoot === '' || !is_dir($procRoot)) {
        return null;
    }

    $pidEntries = @scandir($procRoot);
    if ($pidEntries === false) {
        return null;
    }

    $accountProcessCount = 0;
    $heldBlocks = 0;
    $seenInodes = array();
    foreach ($pidEntries as $pidEntry) {
        if (!ctype_digit($pidEntry) || (int) $pidEntry <= 1) {
            continue;
        }

        $pid = (int) $pidEntry;
        if (pmssUserConfigProcStatusUid($pid, $procRoot) !== $uid) {
            continue;
        }
        $accountProcessCount++;

        $fdRoot = rtrim($procRoot, '/').'/'.$pid.'/fd';
        $fdEntries = @scandir($fdRoot);
        if ($fdEntries === false) {
            return null;
        }

        foreach ($fdEntries as $fdEntry) {
            if (!ctype_digit($fdEntry)) {
                continue;
            }

            $fdPath = $fdRoot.'/'.$fdEntry;
            $stat = $statReader !== null ? $statReader($fdPath) : @stat($fdPath);
            if (!is_array($stat)) {
                return null;
            }

            foreach (array('dev', 'ino', 'nlink', 'uid', 'blocks') as $field) {
                if (!isset($stat[$field]) || !is_numeric($stat[$field])) {
                    return null;
                }
            }

            if ((int) $stat['nlink'] !== 0 || (int) $stat['dev'] !== $homeDevice || (int) $stat['uid'] !== $uid) {
                continue;
            }

            $inodeKey = (string) $stat['dev'].':'.(string) $stat['ino'];
            if (isset($seenInodes[$inodeKey])) {
                continue;
            }
            $seenInodes[$inodeKey] = true;
            $blocks = (int) $stat['blocks'];
            if ($blocks < 0) {
                return null;
            }
            $heldBlocks += $blocks;
        }
    }

    return $accountProcessCount > 0 ? $heldBlocks : null;
}
