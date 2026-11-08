<?php
/** Docker dependency observations and config policy; loaded by docker.php. @license GPL-3.0-only */

/** Cache fuse-overlayfs usability once per process. */
function pmssUserDockerFuseOverlayfsState(): array
{
    static $state = null;
    if ($state !== null) {
        return $state;
    }

    foreach (['/usr/bin/fuse-overlayfs', '/usr/local/bin/fuse-overlayfs'] as $candidate) {
        if (!is_file($candidate) || !is_executable($candidate)) continue;
        $out = [];
        $rc = 1;
        @exec(escapeshellarg($candidate).' --version >/dev/null 2>&1', $out, $rc);
        // The first executable wins even if its version probe fails.
        return $state = ['available' => $rc === 0, 'path' => $candidate];
    }
    return $state = ['available' => false, 'path' => null];
}

/** Verify subid and daemon.json storage-driver state for rootless Docker. */
function pmssEnsureDockerDependencies(string $user): void
{
    if (!pmssUserMaintenanceUsernameAllowed($user, 'pmssEnsureDockerDependencies')) {
        return;
    }

    $subuid = @file_get_contents('/etc/subuid');
    $subgid = @file_get_contents('/etc/subgid');
    if ($subuid === false || strpos($subuid, $user.':') === false) pmssUserLog($user, '[WARN] User missing from /etc/subuid; rootless Docker may fail.');
    if ($subgid === false || strpos($subgid, $user.':') === false) pmssUserLog($user, '[WARN] User missing from /etc/subgid; rootless Docker may fail.');
    $distroVersion = pmssDistroVersionFromEnv();
    if ($distroVersion <= 0) {
        pmssUserLog($user, '[WARN] PMSS_DISTRO_VERSION missing; skipping Docker storage-driver enforcement');
        return;
    }

    $uinfo = pmssUserAccountLookup($user);
    if ($uinfo === null) {
        return;
    }

    $fuse = pmssUserDockerFuseOverlayfsState();
    $home = $uinfo['dir'];
    $configFile = $home.'/.config/docker/daemon.json';
    $hasConfigFile = is_file($configFile);
    $config = !$hasConfigFile || is_link($configFile) ? null : pmssJsonDecodeAssoc((string) @file_get_contents($configFile));
    $dataRoot = pmssUserRootlessDockerDataRoot($home, $config ?? []);
    $storeExists = $dataRoot === '' || pmssUserRootlessDockerStoreExists($dataRoot);
    if ($storeExists) {
        pmssUserLog($user, '[INFO] Existing Docker store found; storage backend left unchanged');
    }
    // dockerd before Engine 24 ignores default-network-opts; only write it where it takes effect.
    $bridgeMtu = pmssUserRootlessDockerBridgeMtuSupported(pmssPackageVersion('docker-ce')) ? ['bridge_mtu' => PMSS_ROOTLESS_DOCKER_BRIDGE_MTU] : [];
    $result = pmssUserRootlessDockerConfigConverge($user, $home, (int) $uinfo['uid'], (int) $uinfo['gid'], [
        'storage_driver' => !$storeExists && $fuse['available'] ? 'fuse-overlayfs' : null,
        'remove_pmss_storage_driver' => !$storeExists && !$fuse['available'],
        'create_when_missing' => !$storeExists && $fuse['available'],
        'invalid_json_as_empty' => true,
        'preserve_custom_storage_driver' => true,
        'disable_containerd_snapshotter' => !$storeExists && $fuse['available'] && $distroVersion <= 11,
    ] + $bridgeMtu);
    if (!$result['ok']) {
        $messages = ['ensure_dir_failed' => '[WARN] Failed to ensure ~/.config/docker', 'encode_failed' => '[WARN] Failed to encode daemon.json', 'write_failed' => '[WARN] Failed to write daemon.json', 'lossy_json' => '[WARN] daemon.json has an empty or numeric-key object PMSS cannot rewrite safely; left unchanged'];
        pmssUserLog($user, $messages[$result['reason']] ?? '[WARN] Failed to converge daemon.json');
        return;
    }
    if (!$fuse['available'] && !$hasConfigFile) {
        pmssUserLog($user, sprintf('[WARN] fuse-overlayfs %s; skipping storage-driver enforcement', $fuse['path'] === null ? 'missing' : 'unusable'));
    }
    // Report in policy order. MTU applies at the next daemon start, without a restart here.
    foreach ([
        'removed_storage_driver' => '[WARN] Removed daemon.json storage-driver because fuse-overlayfs is unavailable',
        'configured_storage_driver' => '[INFO] Configured Docker storage-driver: fuse-overlayfs',
        'disabled_containerd_snapshotter' => '[INFO] Disabled Docker containerd image store for rootless fuse-overlayfs',
        'configured_bridge_mtu' => '[INFO] Configured Docker MTU '.PMSS_ROOTLESS_DOCKER_BRIDGE_MTU.' for new networks (next daemon start; existing networks and the default bridge keep theirs)',
    ] as $key => $message) {
        if ($result[$key]) pmssUserLog($user, $message);
    }
}
