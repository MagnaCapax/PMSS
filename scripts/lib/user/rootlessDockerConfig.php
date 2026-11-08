<?php
/** Rootless Docker daemon.json convergence helper. */

require_once __DIR__.'/../runtime.php';
pmssRequireRelativeFiles(__DIR__, ['directories.php', 'log.php', '../lighttpd/userFileWrite.php', 'rootlessDockerStore.php', 'rootlessDockerPolicy.php']);

/** Return true when daemon.json can be read or replaced safely. */
function pmssUserRootlessDockerConfigTargetIsSafe(string $configFile): bool
{
    return pmssLockFilePathIsSafe($configFile);
}

/**
 * Commands that establish a persistent user runtime dir (/run/user/UID) by enabling
 * linger, so rootless dockerd can bind its API socket there. Pure builder for test coverage.
 *
 * Rootless Docker binds its socket in XDG_RUNTIME_DIR=/run/user/UID, which logind only
 * creates for a logged-in OR lingering user. Accounts whose linger wiring did not complete
 * at provisioning have no runtime dir; the 5-minute checkRootlessDocker watchdog then retries
 * dockerd-rootless.sh forever against a missing dir. Enabling linger makes logind create the
 * persistent dir; starting user@UID brings the user manager up immediately.
 */
function pmssUserDockerLingerEnsureCommands(string $user, int $uid): array
{
    return [
        sprintf('loginctl enable-linger %s', escapeshellarg($user)),
        sprintf('systemctl start user@%d.service', $uid),
    ];
}

/** Atomically replace daemon.json as its account; other callers use the opened-inode writer. */
function pmssUserRootlessDockerConfigWrite(string $configFile, string $json, int $uid, int $gid, ?string &$reason = null): bool
{
    $reason = null;
    if (!pmssUserRootlessDockerConfigTargetIsSafe($configFile)) {
        $reason = 'unsafe_config_file';
        return false;
    }

    if (function_exists('posix_geteuid') && (int) @posix_geteuid() === 0) {
        $identity = pmssAccountHomeIdentity($configFile);
        $account = function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : false;
        // The account writer keeps all staging and publication under the account identity.
        $ok = $uid > 0 && $identity !== null && $identity[2] === $uid
            && is_array($account) && $account['gid'] === $gid
            && pmssReplaceAccountFile($identity[1], $identity[0], $configFile, $json, 0600, true);
    } else {
        $ok = pmssReplaceUserFile($configFile, $json, static function (string $openedPath): bool {
            return @chmod($openedPath, 0600);
        });
    }
    if (!$ok) $reason = 'write_failed';
    return $ok;
}

/**
 * Converge ~/.config/docker/daemon.json according to the requested policy.
 *
 * @return array{ok:bool,changed:bool,created:bool,removed_storage_driver:bool,configured_storage_driver:bool,disabled_containerd_snapshotter:bool,configured_bridge_mtu:bool,reason:string,path:string}
 */
function pmssUserRootlessDockerConfigConverge(string $user, string $home, int $uid, int $gid, array $policy): array
{
    $configFile = rtrim($home, '/').'/.config/docker/daemon.json';
    $result = [
        'ok' => false, 'changed' => false, 'created' => false,
        'removed_storage_driver' => false,
        'configured_storage_driver' => false,
        'disabled_containerd_snapshotter' => false,
        'configured_bridge_mtu' => false,
        'reason' => '', 'path' => $configFile,
    ];

    if (!pmssEnsureUserHomeDir($user, $home, '.config/docker', 0700, static function (string $message) use ($user): void {
        pmssUserLog($user, $message);
    }, 0700)) {
        $result['reason'] = 'ensure_dir_failed';
        return $result;
    }
    if (!pmssUserRootlessDockerConfigTargetIsSafe($configFile)) {
        $result['reason'] = 'unsafe_config_file';
        return $result;
    }

    $hasConfigFile = is_file($configFile);
    $current = $hasConfigFile ? @file_get_contents($configFile) : false;
    // A failed read is not an empty config: replacing it would discard user settings.
    if ($hasConfigFile && $current === false) {
        $result['reason'] = 'read_failed';
        return $result;
    }
    $data = [];
    $decoded = null;
    if ($current !== false && trim((string) $current) !== '') {
        $decoded = pmssJsonDecodeAssoc((string) $current);
        if ($decoded === null && empty($policy['invalid_json_as_empty'])) {
            $result['reason'] = 'invalid_json';
            return $result;
        }
        $data = $decoded ?? [];
    }

    if (!pmssUserRootlessDockerConfigPolicyApply($data, $hasConfigFile, $policy, $result)) {
        $result['ok'] = true;
        return $result;
    }
    // An empty top-level decode ({}) is safe: the written file always gains keys.
    if ($decoded !== null && $decoded !== [] && !pmssUserRootlessDockerConfigRoundTrips((string) $current, $decoded)) {
        // Leave the account's file as it is rather than write one Docker would refuse to load.
        foreach (['removed_storage_driver', 'configured_storage_driver', 'disabled_containerd_snapshotter', 'configured_bridge_mtu'] as $key) {
            $result[$key] = false;
        }
        $result['reason'] = 'lossy_json';
        return $result;
    }

    $json = pmssJsonEncodePretty($data);
    if ($json === null) {
        $result['reason'] = 'encode_failed';
        return $result;
    }
    $writeReason = null;
    if (!pmssUserRootlessDockerConfigWrite($configFile, $json, $uid, $gid, $writeReason)) {
        $result['reason'] = $writeReason ?? 'write_failed';
        return $result;
    }

    $result['ok'] = true;
    $result['changed'] = true;
    $result['created'] = !$hasConfigFile;
    return $result;
}
