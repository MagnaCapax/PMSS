<?php
/**
 * Per-account read-limit status for the panel (ADR 0232).
 *
 * Each enforce cycle publishes the read rate the kernel delivers for the account's cap and
 * whether the account read at it during the last cycle. The file is root-owned in the
 * account's home with the account's read group, like ~/.quota, so the panel needs no
 * cgroupfs access.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../lighttpd/rootStage.php';

const PMSS_READ_LIMIT_STATUS_FILE = '.readLimit';
/** Caps at or above this are placeholders, not limits. */
const PMSS_READ_LIMIT_UNLIMITED = 1000000;

/** Reads per second the throttle delivers for a cap: whole tens per 100 ms slice. */
function pmssReadLimitDelivered(?int $cap): ?int
{
    if ($cap === null || $cap <= 0 || $cap >= PMSS_READ_LIMIT_UNLIMITED) return null;
    return $cap < 10 ? $cap : 10 * intdiv($cap, 10);
}

/** One status document for the panel; $reason 'monthly-budget' marks a cap held by the monthly IOPS budget (ADR 0325). */
function pmssReadLimitStatusJson(int $limit, bool $held, int $now, ?string $reason = null): string
{
    $doc = ['read_limit' => $limit, 'held' => $held, 'updated' => $now];
    if ($reason !== null) $doc['reason'] = $reason;
    return json_encode($doc)."\n";
}

/** Publish every account's status; failures warn and never affect the caps. */
function pmssReadLimitStatusPublish(array $caps, array $held, int $now, ?string $reason = null): int
{
    $home = rtrim((string) (getenv('PMSS_HOME_DIR') ?: '/home'), '/');
    $failed = 0;
    foreach ($caps as $user => $cap) {
        $limit = pmssReadLimitDelivered(is_int($cap) ? $cap : null);
        if ($limit === null) continue;
        $path = $home.'/'.$user.'/'.PMSS_READ_LIMIT_STATUS_FILE;
        if (!pmssRootStageFileWrite($path, pmssReadLimitStatusJson($limit, !empty($held[$user]), $now, $reason), (string) $user, 0640)) {
            $failed++;
            syslog(LOG_WARNING, "read-limit status $user: publish failed");
        }
    }
    return $failed;
}
