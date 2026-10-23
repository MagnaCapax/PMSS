<?php
/**
 * Durable partial-user-refresh state, loaded through stepPolicy.php.
 *
 * Marker validation and persistence stay separate from generic step severity.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Durable marker path for an incomplete per-user-maintenance tail (GH#592).
 *
 * Overridable via PMSS_INCOMPLETE_USER_MAINTENANCE_PATH for tests.
 */
function pmssUpdateIncompleteUserMaintenancePath(): string
{
    return pmssResolvePathFromEnv('PMSS_INCOMPLETE_USER_MAINTENANCE_PATH', '/var/lib/pmss/update-incomplete-users.json');
}

/**
 * Validate the durable marker path before creating/removing it.
 */
function pmssUpdateIncompleteUserMaintenancePathIsSafe(string $path): bool
{
    return pmssPathAbsoluteStringIsSafe($path, ['allowRoot' => false, 'allowTrailingSlash' => false])
        && pmssPathTargetIsSafe($path, false);
}

/**
 * Record that per-user maintenance finished with a skipped tail (GH#592).
 *
 * Replaces the only real benefit the former MUST_SUCCEED hard-fail provided —
 * surfacing the incomplete tail — without blocking the whole system update.
 * The marker is durable (survives reboot) so operators / agentDiagnostics /
 * MOTD can see that some users still need a permission+skel refresh.
 *
 * @param array<int, string> $skipReasons
 */
function pmssUpdateRecordIncompleteUserMaintenance(int $processed, int $total, array $skipReasons): void
{
    $path = pmssUpdateIncompleteUserMaintenancePath();
    if (!pmssUpdateIncompleteUserMaintenancePathIsSafe($path)) {
        logmsg('[WARN] Refusing unsafe incomplete user maintenance marker path: '.$path);
        return;
    }

    $dir = dirname($path);
    if (!pmssDirEnsureExists($dir, 0755)) {
        logmsg('[WARN] Unable to create incomplete user maintenance marker directory: '.$dir);
        return;
    }

    $payload = [
        'ts'        => gmdate('c'),
        'processed' => $processed,
        'total'     => $total,
        'skipped'   => array_values($skipReasons),
    ];
    $encoded = pmssJsonEncodePrettyLine($payload);
    // A partial marker cannot reliably describe the skipped maintenance tail.
    if (!is_string($encoded) || !pmssFileWriteComplete($path, $encoded)) {
        logmsg('[WARN] Unable to write incomplete user maintenance marker: '.$path);
    }
}

/**
 * Clear the incomplete-tail marker once a run processes all users (GH#592).
 */
function pmssUpdateClearIncompleteUserMaintenance(): void
{
    $path = pmssUpdateIncompleteUserMaintenancePath();
    if (!pmssUpdateIncompleteUserMaintenancePathIsSafe($path)) {
        logmsg('[WARN] Refusing unsafe incomplete user maintenance marker path: '.$path);
        return;
    }

    if (is_file($path) && !@unlink($path)) {
        logmsg('[WARN] Unable to clear incomplete user maintenance marker: '.$path);
    }
}

/**
 * Apply GH#592 partial user-maintenance policy to the update-step2 summary.
 */
function pmssUpdateStep2HandleUserMaintenanceSummary($summary): void
{
    if (!is_array($summary)) return;

    $total = (int) ($summary['total'] ?? 0);
    $processed = (int) ($summary['processed'] ?? 0);
    if ($processed >= $total) {
        pmssUpdateClearIncompleteUserMaintenance();
        return;
    }

    $skipReasons = is_array($summary['skip_reasons'] ?? null) ? $summary['skip_reasons'] : [];
    pmssUpdateRecordIncompleteUserMaintenance($processed, $total, $skipReasons);
    $reason = sprintf('processed_users_mismatch:%d_of_%d', $processed, $total);
    if ($skipReasons !== []) $reason .= ' skipped=['.implode('; ', array_slice($skipReasons, 0, 10)).']';
    pmssUpdateStep2HandleClassifiedFailure(
        'Updating all user environments',
        PMSS_UPDATE_STEP_CLASS_SOFT_FAIL,
        1,
        $reason
    );
}
