<?php
/**
 * Install user@ manager file descriptor limits from cgroup policy.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__).'/runtime/commands.php';

/**
 * Render and install LimitNOFILE drop-in for user@.service when configured.
 */
function pmssSystemdUserManagerNoFileLimitInstall(array $policy, callable $log): void
{
    if (!array_key_exists('limitNoFileSoft', $policy) && !array_key_exists('limitNoFileHard', $policy)) {
        return;
    }

    $soft = (isset($policy['limitNoFileSoft']) && is_numeric($policy['limitNoFileSoft'])) ? max(0, (int)$policy['limitNoFileSoft']) : 0;
    $hard = (isset($policy['limitNoFileHard']) && is_numeric($policy['limitNoFileHard'])) ? max(0, (int)$policy['limitNoFileHard']) : 0;

    if ($soft === 0 && $hard === 0) {
        $log('[SKIP] No LimitNOFILE values found in cgroup policy');
        return;
    }

    $soft = $soft ?: $hard;
    $hard = max($soft, $hard);

    $dropDir = pmssResolvePathFromEnv('PMSS_SYSTEMD_USER_AT_SERVICE_DIR', '/etc/systemd/system/user@.service.d');
    if (!is_dir($dropDir) && !@mkdir($dropDir, 0755, true)) {
        $log('[WARN] Failed to create user@.service drop-in dir '.$dropDir);
        return;
    }

    $target = $dropDir.'/20-pmss-limits.conf';
    $body = "# PMSS: per-user manager descriptor limits from cgroup.policy.php\n[Service]\nLimitNOFILE={$soft}:{$hard}\n";

    if (@file_put_contents($tmpTarget = $target.'.tmp', $body) === false) {
        $log('[WARN] Failed to write temp user@.service drop-in '.$tmpTarget);
        return;
    }
    @chmod($tmpTarget, 0644);

    if (!@rename($tmpTarget, $target)) {
        $log('[WARN] Failed to install user@.service drop-in '.$target);
        @unlink($tmpTarget);
        return;
    }

    $log(sprintf('Installed %s with LimitNOFILE=%d:%d', $target, $soft, $hard));
}

/**
 * Install user@ manager log namespace drop-in.
 *
 * This keeps per-user manager/service logs in dedicated journald
 * namespaces, reducing cross-tenant log mixing in shared environments.
 */
function pmssSystemdUserManagerLogNamespaceInstall(callable $log): void
{
    $dropDir = pmssResolvePathFromEnv('PMSS_SYSTEMD_USER_AT_SERVICE_DIR', '/etc/systemd/system/user@.service.d');
    if (!is_dir($dropDir) && !@mkdir($dropDir, 0755, true)) {
        $log('[WARN] Failed to create user@.service drop-in dir '.$dropDir);
        return;
    }

    $target = $dropDir.'/30-pmss-log-namespace.conf';
    $body = "# PMSS: isolate per-user manager logs in dedicated namespaces\n[Service]\nLogNamespace=user-%i\n";

    if (@file_put_contents($tmpTarget = $target.'.tmp', $body) === false) {
        $log('[WARN] Failed to write temp user@.service log namespace drop-in '.$tmpTarget);
        return;
    }
    @chmod($tmpTarget, 0644);

    if (!@rename($tmpTarget, $target)) {
        $log('[WARN] Failed to install user@.service log namespace drop-in '.$target);
        @unlink($tmpTarget);
        return;
    }

    $log('Installed '.$target.' with LogNamespace=user-%i');
}
