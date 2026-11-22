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
require_once __DIR__.'/../update/systemPrep/renderNodeAccess.php';

/**
 * Extract the rTorrent PID from a session lock file.
 */
function pmssUserConfigRtorrentLockPid(string $lockFile): ?int
{
    $raw = pmssReadRegularFileTrimmed($lockFile);
    if ($raw === null) {
        return null;
    }

    $pidText = trim(explode(':+', $raw, 2)[0]);
    if (preg_match('/^[1-9][0-9]*$/D', $pidText) !== 1) {
        return null;
    }

    return (int) $pidText > 1 ? (int) $pidText : null;
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
    return $status !== null && preg_match('/^Uid:\s+([0-9]+)\s+/m', $status, $matches) === 1
        ? (int) $matches[1] : null;
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

function pmssUserConfigInvocationMode(array $args, bool $welcomeMessageProvided, bool $namedConfigChange): string
{
    if (!empty($args[1]) && !empty($args[2]) && !empty($args[3])) return 'full';
    if (!empty($args[1]) && empty($args[2]) && empty($args[3]) && $namedConfigChange) return 'named';
    if (!empty($args[1]) && $welcomeMessageProvided && empty($args[2]) && empty($args[3])) return 'welcome';
    return '';
}
/** Only full mode carries an explicit disk quota that is safe to apply. */
function pmssUserConfigDiskQuotaShouldApply(string $configMode): bool
{
    return $configMode === 'full';
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

    unset($existing['welcomeMessage']);
    return $store->persist($username, $existing) ? null : "Error: failed to persist user config for {$username}";
}
function pmssUserConfigNamedModeUser(array $user, array $existing, array $explicitResourceOverrides): array
{
    return array_merge($user, ['memory' => (int) $existing['ramMiB'], 'quota' => (int) $existing['quota']],
        pmssUserConfigCliPersistedStoredResources($existing), $explicitResourceOverrides);
}
function pmssUserConfigPayloadBuild($store, array $existing, array $user, array $presence, ?bool $dockerEnabled, ?bool $gpuEnabled = null): array
{
    $payload = array_merge($existing, ['ramMiB' => $user['memory'], 'rtorrentPort' => (int) ($existing['rtorrentPort'] ?? 0), 'quota' => $user['quota'], 'quotaBurst' => (int) round(((float) $user['quota']) * 1.25), 'trafficLimit' => 0]);
    $payload = pmssUserConfigCliApplyPersistedResources($payload, $user, $presence);
    foreach (['billingServiceId', 'billingClientId'] as $key) $payload[$key] = $payload[$key] ?? 0;
    if ($payload['billingServiceId'] === 0 || $payload['billingClientId'] === 0) {
        $payload = $store->applyFallbacks($user['name'], $payload);
    }
    if ($dockerEnabled !== null) $payload['dockerEnabled'] = $dockerEnabled;
    if ($gpuEnabled !== null) $payload['gpuEnabled'] = $gpuEnabled;
    return $payload;
}


require_once __DIR__.'/userConfigPrepare.php';
require_once __DIR__.'/userConfigLimits.php';
require_once __DIR__.'/userConfigServices.php';
