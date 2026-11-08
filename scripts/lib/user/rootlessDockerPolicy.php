<?php
/** Pure rootless Docker config transforms and format checks. @license GPL-3.0-only */

/**
 * Bridge MTU for rootless Docker networks. dockerd-rootless.sh gives the RootlessKit
 * tap link (slirp4netns) a 65520 MTU; bridges at Docker's 1500 default make every
 * container cut TCP into 1460-byte segments that slirp4netns then handles one by one.
 * The value only exists inside the account's network namespace: slirp4netns opens
 * normal host sockets, so ordinary traffic leaves the host at the real path MTU.
 */
const PMSS_ROOTLESS_DOCKER_BRIDGE_MTU = 65520;

/**
 * Return true when the decoded array re-encodes to the same JSON value. json_decode(..., true)
 * cannot tell {} from [], and Docker refuses a map written back as a list ("log-opts": []).
 */
function pmssUserRootlessDockerConfigRoundTrips(string $raw, array $decoded): bool
{
    $reencoded = json_encode($decoded);
    return is_string($reencoded) && json_encode(json_decode($raw)) === json_encode(json_decode($reencoded));
}

/** Return true for a decoded JSON object; a non-empty list is not one ([] may be either). */
function pmssUserRootlessDockerIsJsonObject($value): bool
{
    return is_array($value) && ($value === [] || array_values($value) !== $value);
}

/**
 * Return true when the installed docker-ce applies default-network-opts (Engine 24 and later).
 * An empty or unparsable version (package missing) returns false, so the key is not written.
 */
function pmssUserRootlessDockerBridgeMtuSupported(string $dockerCeVersion): bool
{
    // Debian versions look like 5:24.0.7-1~debian.12~bookworm: drop the epoch, keep the upstream part.
    return preg_match('/^(?:\d+:)?(\d+(?:\.\d+)*)/', $dockerCeVersion, $match) === 1
        && version_compare($match[1], '24', '>=');
}

/**
 * Fill the bridge MTU network option of a daemon.json payload: default-network-opts
 * covers networks created afterwards (existing networks keep their MTU until
 * recreated). The default bridge ("mtu") is left alone, so containers that already
 * exist keep 1500 across daemon restarts. A value the account already set, valid
 * or not, is kept as it is. Returns true when the payload changed.
 */
function pmssUserRootlessDockerBridgeMtuApply(array &$data, $mtu): bool
{
    if (!is_int($mtu) || $mtu < 1280 || $mtu > 65535) {
        return false;
    }
    $opts = array_key_exists('default-network-opts', $data) ? $data['default-network-opts'] : [];
    $bridge = pmssUserRootlessDockerIsJsonObject($opts) ? (array_key_exists('bridge', $opts) ? $opts['bridge'] : []) : null;
    // A non-object value (lists included) belongs to the account; Docker reports it, PMSS does not repair it.
    if (pmssUserRootlessDockerIsJsonObject($bridge) && !array_key_exists('com.docker.network.driver.mtu', $bridge)) {
        $bridge['com.docker.network.driver.mtu'] = (string) $mtu;
        $opts['bridge'] = $bridge;
        $data['default-network-opts'] = $opts;
        return true;
    }
    return false;
}

/** Apply daemon policy without I/O; retain operation flags in the caller's result. */
function pmssUserRootlessDockerConfigPolicyApply(array &$data, bool $hasConfigFile, array $policy, array &$result): bool
{
    $original = $data;
    $desiredDriver = is_string($policy['storage_driver'] ?? null) ? $policy['storage_driver'] : null;

    if (!empty($policy['remove_pmss_storage_driver']) && ($data['storage-driver'] ?? null) === 'fuse-overlayfs') {
        unset($data['storage-driver']);
        $result['removed_storage_driver'] = true;
    } elseif ($desiredDriver !== null) {
        $currentDriver = $data['storage-driver'] ?? null;
        if ($currentDriver !== $desiredDriver && ($currentDriver === null
            || (array_key_exists('preserve_custom_storage_driver', $policy) && !$policy['preserve_custom_storage_driver']))) {
            $data['storage-driver'] = $desiredDriver;
            $result['configured_storage_driver'] = true;
        }
    }

    if (!empty($policy['disable_containerd_snapshotter'])) {
        // Docker reads storage backend features only at daemon startup. This
        // preserves existing feature keys while forcing the classic graphdriver.
        $features = is_array($data['features'] ?? null) ? $data['features'] : [];
        if (!array_key_exists('containerd-snapshotter', $features) || $features['containerd-snapshotter'] !== false) {
            $features['containerd-snapshotter'] = false;
            $data['features'] = $features;
            $result['disabled_containerd_snapshotter'] = true;
        }
    }

    $writeConfig = $hasConfigFile || !empty($policy['create_when_missing']) || $data !== $original;
    // The MTU fills a file that exists or is written anyway; it never creates one on its own.
    $result['configured_bridge_mtu'] = array_key_exists('bridge_mtu', $policy) && $writeConfig
        && pmssUserRootlessDockerBridgeMtuApply($data, $policy['bridge_mtu']);

    if ($writeConfig) {
        $execOpts = is_array($data['exec-opts'] ?? null) ? $data['exec-opts'] : [];
        if (!in_array('native.cgroupdriver=cgroupfs', $execOpts, true)) {
            $execOpts[] = 'native.cgroupdriver=cgroupfs';
            $data['exec-opts'] = array_values(array_unique($execOpts));
        }
    }
    return $data !== $original;
}
