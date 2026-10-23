<?php
/**
 * Phase-2 abnormal-exit recovery, loaded after the updater runtime.
 *
 * Registration stays in the entrypoint before lock acquisition. Recovery uses
 * the existing pending/completed flags and never changes normal phase ordering.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** Return a stable abnormal-exit reason for shutdown rescue logs. */
function pmssUpdateStep2ShutdownReason(): string
{
    $error = error_get_last();
    return (is_array($error) && is_string($error['message'] ?? null) && $error['message'] !== '')
        ? $error['message'] : 'early_exit';
}

/** Run a rescue command with the legacy event payloads and execution mode. */
function pmssUpdateStep2RunRescueCommand(string $event, string $reason, string $command, ?string $description = null): int
{
    pmssLogJson(['event' => $event, 'status' => 'start', 'reason' => $reason]);
    $rc = 0;
    // Web recovery bypasses dry-run wrappers; permissions retain runStep profiling.
    if ($description === null) passthru(pmssLockChildClosePrefix().$command, $rc);
    else $rc = runStep($description, $command);
    pmssLogJson(['event' => $event, 'status' => $rc === 0 ? 'ok' : 'error', 'rc' => $rc]);
    return $rc;
}

/** Attempt a best-effort nginx start when the final refresh could not finish. */
function pmssUpdateStep2StartNginxShutdownFallback(string $reason): void
{
    logmsg('[WARN] update-step2 exited with nginx still pending final refresh; attempting direct start (reason: '.$reason.')');
    $rc = pmssUpdateStep2RunRescueCommand('post_update_nginx_start_fallback', $reason,
        'systemctl start nginx 2>/dev/null || /etc/init.d/nginx start 2>/dev/null');
    logmsg(sprintf('[WARN] Direct nginx start fallback completed with rc=%d', $rc));
}

/** Restore the web stack and tenant-visible permissions after an early exit. */
function pmssUpdateStep2RegisterShutdownGuard(): void
{
    register_shutdown_function(static function (): void {
        if (!empty($GLOBALS['PMSS_UPDATE_STEP2_COMPLETED'])) return;

        $refreshPending = !empty($GLOBALS['PMSS_UPDATE_STEP2_WEB_REFRESH_PENDING']);
        $permissionPending = !pmssEnvFlagEnabled('PMSS_DRY_RUN');
        if (!$refreshPending && !$permissionPending) return;

        $reason = pmssUpdateStep2ShutdownReason();
        if ($refreshPending) {
            if (!file_exists('/scripts/util/createNginxConfig.php')) {
                logmsg('[WARN] update-step2 exited before final nginx refresh; createNginxConfig.php missing');
                pmssUpdateStep2StartNginxShutdownFallback('create_nginx_config_missing');
            } else {
                logmsg('[WARN] update-step2 exited before final nginx refresh; attempting rescue run (reason: '.$reason.')');
                $rc = pmssUpdateStep2RunRescueCommand('post_update_web_refresh_rescue', $reason,
                    '/scripts/util/createNginxConfig.php --reload');
                logmsg(sprintf('[WARN] Rescue nginx refresh completed with rc=%d', $rc));
                if ($rc !== 0) pmssUpdateStep2StartNginxShutdownFallback('web_refresh_rescue_failed');
            }
        }

        if (!$permissionPending) return;
        $helper = '/scripts/util/setupPermissions.php';
        if (!is_file($helper)) {
            logmsg('[WARN] update-step2 exited before final permission refresh; setupPermissions.php missing');
            return;
        }
        // Re-read after web recovery: an intervening warning can change the reason.
        $reason = pmssUpdateStep2ShutdownReason();
        logmsg('[WARN] update-step2 exited before final permission refresh; attempting permission rescue run (reason: '.$reason.')');
        pmssUpdateStep2RunRescueCommand('permission_refresh_rescue', $reason, $helper,
            'Restoring system permissions (shutdown)');
    });
}
