<?php
/** Apply reconfiguration GPU, cgroup and Docker policy after persistence. @license GPL-3.0-only */

/**
 * Build the operator-facing cgroup failure warning.
 */
function pmssUserConfigCgroupApplyFailureMessage(string $username, int $rc): string
{
    $safeUser = pmssLogSafeLabel($username);
    return sprintf(
        'Warning: cgroup configuration failed for %s (rc=%d); update-step2 will check and retry slice policy drift',
        $safeUser,
        $rc
    );
}

/**
 * Surface a failed cgroup apply without converting it into fresh-account rollback.
 */
function pmssUserConfigCgroupApplyFailureLog(string $username, int $rc): void
{
    $message = pmssUserConfigCgroupApplyFailureMessage($username, $rc);
    $safeUser = pmssLogSafeLabel($username);
    fwrite(STDERR, $message."\n");
    if (function_exists('logMessage')) {
        logMessage($message);
    }
    if (function_exists('pmssLogJson')) {
        pmssLogJson([
            'event' => 'user_config_cgroup_apply_failed',
            'level' => 'warn',
            'user'  => $safeUser,
            'rc'    => $rc,
        ]);
    }
}

/** Reconcile a changed, successfully persisted GPU policy on Intel or AMD iGPU hosts only. */
function pmssUserConfigApplyGpuPolicy(string $username, array $existing, array $payload, ?callable $intelProbe = null, ?callable $denyConverge = null, ?callable $groupMember = null): void
{
    if (!pmssValidateUsername($username)
        || pmssUserConfigNormaliseToggleValue($existing, 'gpuEnabled') === pmssUserConfigNormaliseToggleValue($payload, 'gpuEnabled')) return;
    $intelProbe = $intelProbe ?: 'pmssHostHasRenderGpuDisplayPciDevice';
    if (!$intelProbe()) return;
    $denyConverge = $denyConverge ?: 'pmssEnsureIntelRenderNodeDenies';
    $groupMember = $groupMember ?: 'pmssEnsureIntelRenderGroupMember';
    $denyConverge();
    // Named-user denies override old render membership; never remove existing members.
    if (pmssUserConfigNormaliseToggleValue($payload, 'gpuEnabled')) $groupMember($username);
}
function pmssUserConfigApplyCgroupAndDocker(array $user, $store): void
{
    $args = pmssUserConfigCliBuildCgroupApplyArgs($user['name'], (int) $user['memory'], $user);
    if (isset($user['cpuQuotaPercent']) && $user['cpuQuotaPercent'] !== '') {
        echo 'Applying CPU quota: '.((is_string($user['cpuQuotaPercent']) && strtolower($user['cpuQuotaPercent']) === 'infinity')
            ? 'infinity' : $user['cpuQuotaPercent'].'%')."\n";
    }

    $cgroupRc = runStep('Configuring cgroups', pmssBuildCommand('php', $args));
    if ($cgroupRc !== 0) pmssUserConfigCgroupApplyFailureLog($user['name'], $cgroupRc);

    $dockerEnabled = pmssUserDockerEnabled($user['name'], $store);
    $lingerEnabled = pmssUserLingerEnabled($user['name'], $store);
    if ($dockerEnabled || $lingerEnabled) {
        runStep('Enabling linger for user', sprintf('loginctl enable-linger %s', escapeshellarg($user['name'])));
    }
    if (!$dockerEnabled) {
        pmssLogStatus('SKIP', 'Rootless Docker disabled by config for '.$user['name']);
        return;
    }

    runStep('Installing systemd-container tools', 'apt-get install -y systemd-container');
    // Manager-independent rootless-Docker setup (ADR-0027): run the setuptool AS the user (su)
    // with XDG_RUNTIME_DIR set, NOT via the per-user-manager container-shell path which requires
    // a working per-user systemd manager. That manager fails to start under cgroup v2 + hidepid=2
    // (systemd issue 12955), so the old path breaks new Docker provisioning on v2 hosts. The setuptool
    // sets up subuid/subgid + rootlesskit here; the daemon is launched separately via nohup
    // dockerd-rootless.sh (userDocker.php), so a systemd --user unit is not required.
    $dockerSetupCmd = 'export XDG_RUNTIME_DIR=/run/user/$(id -u); export PATH="$PATH:/usr/sbin:/sbin"; /usr/bin/dockerd-rootless-setuptool.sh install';
    runStep('Configuring rootless Docker', pmssBuildUserShellCommand($user['name'], $dockerSetupCmd));
}
