<?php
/**
 * Runtime safety helpers for userConfig.php.
 *
 * Destructive follow-up steps in user reconfiguration must re-check file and
 * process boundaries locally; session lock files can be stale or malformed.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';

/**
 * Extract the rTorrent PID from a session lock file.
 */
function pmssUserConfigRtorrentLockPid(string $lockFile): ?int
{
    $raw = pmssReadRegularFileTrimmed($lockFile);
    if ($raw === null) {
        return null;
    }

    $pidParts = explode(':+', $raw, 2);
    $pidText = trim((string) ($pidParts[0] ?? ''));
    if (preg_match('/^[1-9][0-9]*$/D', $pidText) !== 1) {
        return null;
    }

    $pid = (int) $pidText;
    return $pid > 1 ? $pid : null;
}

/**
 * Read the real UID from a proc status file.
 */
function pmssUserConfigProcStatusUid(int $pid, string $procRoot = '/proc'): ?int
{
    if ($pid <= 1) {
        return null;
    }

    $status = pmssReadRegularFileContents(rtrim($procRoot, '/').'/'.$pid.'/status');
    if ($status === null || preg_match('/^Uid:\s+([0-9]+)\s+/m', $status, $matches) !== 1) {
        return null;
    }

    return (int) $matches[1];
}

/**
 * Confirm a PID still refers to the expected user's rTorrent process.
 */
function pmssUserConfigRtorrentProcessOwnedBy(int $pid, int $uid, string $procRoot = '/proc'): bool
{
    if ($pid <= 1 || $uid < 1000 || pmssUserConfigProcStatusUid($pid, $procRoot) !== $uid) {
        return false;
    }

    $comm = pmssReadRegularFileTrimmed(rtrim($procRoot, '/').'/'.$pid.'/comm');
    return $comm !== null && strpos($comm, 'rtorrent') === 0;
}

/**
 * Build the operator-facing cgroup failure warning.
 */
function pmssUserConfigCgroupApplyFailureMessage(string $username, int $rc): string
{
    $safeUser = preg_replace('/[\r\n\0]+/', '?', $username);
    $safeUser = is_string($safeUser) && $safeUser !== '' ? $safeUser : '(empty)';
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
    $safeUser = preg_replace('/[\r\n\0]+/', '?', $username);
    $safeUser = is_string($safeUser) && $safeUser !== '' ? $safeUser : '(empty)';
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

function pmssUserConfigInvocationMode(array $args, bool $welcomeMessageProvided, bool $namedConfigChange): string
{
    if (!empty($args[1]) && !empty($args[2]) && !empty($args[3])) return 'full';
    if (!empty($args[1]) && empty($args[2]) && empty($args[3]) && $namedConfigChange) return 'named';
    if (!empty($args[1]) && $welcomeMessageProvided && empty($args[2]) && empty($args[3])) return 'welcome';
    return '';
}
function pmssUserConfigBaselineError(array $payload, array $requiredKeys): ?string
{
    foreach ($requiredKeys as $requiredKey) {
        if (!isset($payload[$requiredKey]) || !is_numeric($payload[$requiredKey])) return "Error: missing existing {$requiredKey}; rerun full userConfig.php first.";
    }
    return null;
}
function pmssUserConfigWelcomeOnlyPersist($store, string $username, string $home, ?string $welcomeMessage, array $existing): ?string
{
    $baselineError = pmssUserConfigBaselineError($existing, ['ramMiB', 'rtorrentPort', 'quota', 'quotaBurst']);
    if ($baselineError !== null) return $baselineError;
    if (!pmssWelcomeUserMessageSet($username, $home, $welcomeMessage)) return "Error: failed to persist welcome message for {$username}";

    $payload = $existing;
    unset($payload['welcomeMessage']);
    return $store->persist($username, $payload) ? null : "Error: failed to persist user config for {$username}";
}
function pmssUserConfigNamedModeUser(array $user, array $existing, array $explicitResourceOverrides): array
{
    $user['memory'] = (int) $existing['ramMiB'];
    $user['quota'] = (int) $existing['quota'];
    return array_merge($user, pmssUserConfigCliPersistedStoredResources($existing), $explicitResourceOverrides);
}
function pmssUserConfigPayloadBuild($store, array $existing, array $user, array $presence, ?bool $dockerEnabled): array
{
    $payload = array_merge($existing, ['ramMiB' => $user['memory'], 'rtorrentPort' => isset($existing['rtorrentPort']) ? (int) $existing['rtorrentPort'] : 0, 'quota' => $user['quota'], 'quotaBurst' => (int) round(((float) $user['quota']) * 1.25), 'trafficLimit' => 0]);
    $payload = pmssUserConfigCliApplyPersistedResources($payload, $user, $presence);
    $payload['billingServiceId'] = $payload['billingServiceId'] ?? 0;
    $payload['billingClientId'] = $payload['billingClientId'] ?? 0;
    if ($payload['billingServiceId'] === 0 || $payload['billingClientId'] === 0) {
        $payload = $store->applyFallbacks($user['name'], $payload);
    }
    if ($dockerEnabled !== null) $payload['dockerEnabled'] = $dockerEnabled;
    return $payload;
}
function pmssUserConfigApplyCgroupAndDocker(array $user, $store): void
{
    $args = pmssUserConfigCliBuildCgroupApplyArgs($user['name'], (int) $user['memory'], $user);
    if (isset($user['cpuQuotaPercent']) && $user['cpuQuotaPercent'] !== '') {
        $quotaVal = $user['cpuQuotaPercent'];
        $quotaLabel = (is_string($quotaVal) && strtolower((string) $quotaVal) === 'infinity')
            ? 'infinity'
            : $quotaVal.'%';
        echo 'Applying CPU quota: '.$quotaLabel."\n";
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
