<?php
/**
 * Feature policy helpers backed by the durable per-user config store.
 *
 * Keep these helpers loaded by userConfigStore.php so existing consumers keep
 * one include path while storage and enablement policy stay separate.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** Minimum RAM required for rootless Docker. */
function pmssUserDockerMinRamMiB(): int { return 245; }

/** Convert stored feature-toggle values to a stable boolean. */
function pmssUserConfigNormaliseToggleValue(array $payload, string $key, bool $default = true): bool
{
    if (!array_key_exists($key, $payload)) return $default;
    $value = $payload[$key];
    return is_string($value) ? !pmssValueMatchesNormalized($value, ['false', '0', 'no', 'off', '']) : (bool) $value;
}

/** Load one validated user's persisted payload for policy checks. */
function pmssUserConfigResolvePayload(string $username, ?UserConfigStore &$store = null): ?array
{
    $username = pmssNormalizeUsername($username);
    if (!UserValidator::isValidUsername($username)) return null;
    $store = $store ?: new UserConfigStore();
    $payload = $store->get($username);
    return is_array($payload) ? $payload : [];
}

/** Read a boolean feature toggle after shared username and payload validation. */
function pmssUserConfigFeatureEnabled(string $username, string $key, ?UserConfigStore $store = null): bool
{
    $payload = pmssUserConfigResolvePayload($username, $store);
    return $payload !== null && pmssUserConfigNormaliseToggleValue($payload, $key);
}

/** Check whether rootless Docker should run for a user. */
function pmssUserDockerEnabled(string $username, ?UserConfigStore $store = null): bool
{
    if (!pmssUserConfigFeatureEnabled($username, 'dockerEnabled', $store)) return false;
    $payload = pmssUserConfigResolvePayload($username, $store);
    $configuredRamMiB = isset($payload['ramMiB']) && is_numeric($payload['ramMiB']) ? (int) $payload['ramMiB'] : 0;
    $runtimeRamMiB = $store->resolveRamMiB(pmssNormalizeUsername($username));
    $effectiveRamMiB = ($runtimeRamMiB > 0 && ($configuredRamMiB <= 0 || $runtimeRamMiB < $configuredRamMiB)) ? $runtimeRamMiB : $configuredRamMiB;
    return $effectiveRamMiB <= 0 || $effectiveRamMiB >= pmssUserDockerMinRamMiB();
}

/** Check whether persistent per-user services were explicitly requested. */
function pmssUserLingerEnabled(string $username, ?UserConfigStore $store = null): bool
{
    $payload = pmssUserConfigResolvePayload($username, $store);
    return $payload !== null && pmssUserConfigNormaliseToggleValue($payload, 'lingerEnabled', false);
}

/** Check whether the per-user lighttpd/php-cgi web stack should run. */
function pmssUserLighttpdEnabled(string $username, ?UserConfigStore $store = null): bool
{
    return pmssUserConfigFeatureEnabled($username, 'lighttpdEnabled', $store);
}

/** Check whether the user opted into scheduled config backups. */
function pmssUserScheduledConfigBackupEnabled(string $username, ?UserConfigStore $store = null): bool
{
    $payload = pmssUserConfigResolvePayload($username, $store);
    return $payload !== null && pmssUserConfigNormaliseToggleValue($payload, 'scheduledConfigBackup', false);
}
