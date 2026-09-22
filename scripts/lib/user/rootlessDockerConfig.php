<?php
/** Rootless Docker daemon.json convergence helper. */

require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/directories.php';
require_once __DIR__.'/log.php';

/** Return true when daemon.json can be read or replaced safely. */
function pmssUserRootlessDockerConfigTargetIsSafe(string $configFile): bool
{
    return !is_link($configFile)
        && (!file_exists($configFile) || is_file($configFile));
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

/** Atomically write daemon.json without following unsafe existing targets. */
function pmssUserRootlessDockerConfigWrite(string $configFile, string $json, int $uid, int $gid, ?string &$reason = null): bool
{
    $reason = null;
    if (!pmssUserRootlessDockerConfigTargetIsSafe($configFile)) {
        $reason = 'unsafe_config_file';
        return false;
    }

    $directory = dirname($configFile);
    $tempFile = @tempnam($directory, '.daemon.json.');
    if (!is_string($tempFile)) {
        $reason = 'temp_failed';
        return false;
    }

    $ok = false;
    $isRoot = function_exists('posix_geteuid') && (int) @posix_geteuid() === 0;
    if (@file_put_contents($tempFile, $json) === false) {
        $reason = 'write_failed';
    } elseif ($isRoot && !@chown($tempFile, $uid)) {
        $reason = 'chown_failed';
    } elseif ($isRoot && !@chgrp($tempFile, $gid)) {
        $reason = 'chgrp_failed';
    } elseif (!@chmod($tempFile, 0600)) {
        $reason = 'chmod_failed';
    } elseif (!@rename($tempFile, $configFile)) {
        $reason = 'rename_failed';
    } else {
        $ok = true;
    }

    if (!$ok && is_file($tempFile)) {
        @unlink($tempFile);
    }
    return $ok;
}

/**
 * Converge ~/.config/docker/daemon.json according to the requested policy.
 *
 * @return array{ok:bool,changed:bool,created:bool,removed_storage_driver:bool,configured_storage_driver:bool,disabled_containerd_snapshotter:bool,reason:string,path:string}
 */
function pmssUserRootlessDockerConfigConverge(string $user, string $home, int $uid, int $gid, array $policy): array
{
    $configFile = rtrim($home, '/').'/.config/docker/daemon.json';
    $result = [
        'ok' => false, 'changed' => false, 'created' => false,
        'removed_storage_driver' => false,
        'configured_storage_driver' => false,
        'disabled_containerd_snapshotter' => false,
        'reason' => '', 'path' => $configFile,
    ];

    $logger = static function (string $message) use ($user): void {
        pmssUserLog($user, $message);
    };
    if (!pmssEnsureUserHomeDir($user, $home, '.config/docker', 0700, $logger, 0700)) {
        $result['reason'] = 'ensure_dir_failed';
        return $result;
    }
    if (!pmssUserRootlessDockerConfigTargetIsSafe($configFile)) {
        $result['reason'] = 'unsafe_config_file';
        return $result;
    }

    $hasConfigFile = is_file($configFile);
    $current = $hasConfigFile ? @file_get_contents($configFile) : false;
    $data = [];
    if ($current !== false && trim((string) $current) !== '') {
        $decoded = pmssJsonDecodeAssoc((string) $current);
        if ($decoded === null && empty($policy['invalid_json_as_empty'])) {
            $result['reason'] = 'invalid_json';
            return $result;
        }
        $data = $decoded ?? [];
    }

    $changed = false;
    $desiredDriver = isset($policy['storage_driver']) && is_string($policy['storage_driver']) ? $policy['storage_driver'] : null;
    $preserveCustomDriver = array_key_exists('preserve_custom_storage_driver', $policy) ? (bool) $policy['preserve_custom_storage_driver'] : true;

    if (!empty($policy['remove_pmss_storage_driver']) && ($data['storage-driver'] ?? null) === 'fuse-overlayfs') {
        unset($data['storage-driver']);
        $changed = true;
        $result['removed_storage_driver'] = true;
    } elseif ($desiredDriver !== null) {
        $currentDriver = $data['storage-driver'] ?? null;
        if (!$preserveCustomDriver || $currentDriver === null || $currentDriver === $desiredDriver) {
            if ($currentDriver !== $desiredDriver) {
                $data['storage-driver'] = $desiredDriver;
                $changed = true;
                $result['configured_storage_driver'] = true;
            }
        }
    }

    if (!empty($policy['disable_containerd_snapshotter'])) {
        // Docker reads storage backend features only at daemon startup. This
        // preserves existing feature keys while forcing the classic graphdriver.
        $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
        if (!array_key_exists('containerd-snapshotter', $features) || $features['containerd-snapshotter'] !== false) {
            $features['containerd-snapshotter'] = false;
            $data['features'] = $features;
            $changed = true;
            $result['disabled_containerd_snapshotter'] = true;
        }
    }

    $createWhenMissing = !empty($policy['create_when_missing']);
    if ($hasConfigFile || $createWhenMissing || $changed) {
        $execOpts = isset($data['exec-opts']) && is_array($data['exec-opts']) ? $data['exec-opts'] : [];
        if (!in_array('native.cgroupdriver=cgroupfs', $execOpts, true)) {
            $execOpts[] = 'native.cgroupdriver=cgroupfs';
            $data['exec-opts'] = array_values(array_unique($execOpts));
            $changed = true;
        }
    }
    if (!$changed) {
        $result['ok'] = true;
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
