<?php
/**
 * Persist sampled cgroup-v1 OOM kills in root-owned state, then project to the account.
 * The first sample is only a baseline: a cumulative counter has no event time.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/log.php';

/** Return a bounded-recency state after one valid collector sample. */
function pmssOomStatusStateNext(array $previous, int $count, int $now): array
{
    $oldCount = $previous['count'] ?? null;
    $lastIncrease = $previous['last_increase'] ?? null;
    if (!is_int($oldCount) || $oldCount < 0
        || ($lastIncrease !== null && (!is_int($lastIncrease) || $lastIncrease < 0 || $lastIncrease > $now))) {
        $oldCount = null;
        $lastIncrease = null;
    }
    if ($oldCount !== null && ($count > $oldCount || ($count < $oldCount && $count > 0))) {
        $lastIncrease = $now;
    }
    if ($count === 0) {
        $lastIncrease = null;
    }
    return ['count' => $count, 'last_increase' => $lastIncrease, 'sampled_at' => $now];
}

/** Atomically publish only the owning user's counter and last observed increase. */
function pmssOomStatusProject(string $user, int $count, int $now, string $homeRoot = '/home', string $stateRoot = '/var/run/pmss/oomStatus'): bool
{
    $home = userFilesystem::homePath($user, $homeRoot);
    if (!pmssResourceUserIsValid($user) || !is_dir($home) || is_link($home) || $count < 0 || $now < 0) return false;
    $result = pmssCounterStateUpdate($stateRoot.'/'.$user.'.json', ['count' => $count, 'sampled_at' => $now], [], [],
        static function (array $previous, array $current): array {
            return pmssOomStatusStateNext($previous, $current['count'], $current['sampled_at']);
        });
    if (!$result['persisted']) return false;
    $path = $home.'/.oomKillStatus';
    // A tenant controls this home; the managed writer rejects unsafe targets without reading them.
    return pmssManagedSerializedTargetsWrite(serialize($result['state']), [[$path, $user, 0640, false]],
        static function (string $_path): void {});
}
