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

/** Minimum RAM for preinstalling Pikkumies on an account: the build measured to fit a 400 MiB plan (ADR 0090, 2026-10-20). */
function pmssUserPikkumiesPreinstallMinRamMiB(): int { return 400; }

/** Convert stored feature-toggle values to a stable boolean. */
function pmssUserConfigNormaliseToggleValue(array $payload, string $key, bool $default = true): bool
{
    if (!array_key_exists($key, $payload)) return $default;
    $value = $payload[$key];
    return is_string($value) ? !pmssValueMatchesNormalized($value, ['false', '0', 'no', 'off', '']) : (bool) $value;
}

/** Accept only a canonical calendar date, without trimming or coercion. */
function pmssUserMovedDateIsValid($value): bool
{
    if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) return false;
    return checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
}

/** Read the removal date; passing the date never automatically unparks services. */
function pmssUserMovedUntil(string $username, ?UserConfigStore $store = null): ?string
{
    if (!pmssValidateUsername($username)) return null;
    $payload = ($store ?? new UserConfigStore())->get($username);
    return $payload['movedUntil'] ?? null;
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
    $configuredRamMiB = is_numeric($payload['ramMiB'] ?? null) ? (int) $payload['ramMiB'] : 0;
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

/** Read the opt-in panel session-cookie login flag from a normalized payload. */
function pmssUserPanelSessionLoginEnabledFromPayload(array $payload): bool
{
    return pmssUserConfigNormaliseToggleValue($payload, 'panelSessionLogin', false);
}

/** Check whether the user opted into panel session-cookie login. */
function pmssUserPanelSessionLoginEnabled(string $username, ?UserConfigStore $store = null): bool
{
    $payload = pmssUserConfigResolvePayload($username, $store);
    return $payload !== null && pmssUserPanelSessionLoginEnabledFromPayload($payload);
}
