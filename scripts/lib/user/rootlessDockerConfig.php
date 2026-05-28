<?php
/** Rootless Docker daemon.json convergence helper. */

require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/directories.php';
require_once __DIR__.'/log.php';

/**
 * Converge ~/.config/docker/daemon.json according to the requested policy.
 *
 * @return array{ok:bool,changed:bool,created:bool,removed_storage_driver:bool,configured_storage_driver:bool,reason:string,path:string}
 */
function pmssUserRootlessDockerConfigConverge(string $user, string $home, int $uid, int $gid, array $policy): array
{
    $configFile = rtrim($home, '/').'/.config/docker/daemon.json';
    $result = [
        'ok' => false, 'changed' => false, 'created' => false,
        'removed_storage_driver' => false,
        'configured_storage_driver' => false,
        'reason' => '', 'path' => $configFile,
    ];

    $logger = static function (string $message) use ($user): void {
        pmssUserLog($user, $message);
    };
    if (!pmssEnsureUserHomeDir($user, $home, '.config/docker', 0700, $logger, 0700)) {
        $result['reason'] = 'ensure_dir_failed';
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
    if (@file_put_contents($configFile, $json) === false) {
        $result['reason'] = 'write_failed';
        return $result;
    }

    @chown($configFile, $uid);
    @chgrp($configFile, $gid);
    @chmod($configFile, 0600);
    $result['ok'] = true;
    $result['changed'] = true;
    $result['created'] = !$hasConfigFile;
    return $result;
}
