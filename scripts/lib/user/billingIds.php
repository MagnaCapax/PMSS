<?php
/**
 * Per-user billing identity readers.
 *
 * Billing service IDs are read from the new self-describing file name first
 * and fall back to the legacy .billingId file during the migration window.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/identity.php';

/** Read a positive digit-only identifier without following symlinks. */
function pmssUserBillingDigitsFileRead(string $path, bool $requireRootOwner = false): ?string
{
    $contents = pmssReadRegularFileContentsVerified($path, $requireRootOwner ? 0 : null, 256);
    if ($contents === null) return null;
    $raw = trim($contents, " \t\r\n");
    $id = pmssUnsignedDecimalIntParse($raw);
    return $id !== null && $id > 0 ? $raw : null;
}

/** Accept only the billing dot-file basenames this helper is meant to probe. */
function pmssUserBillingFileNameIsSafe(string $fileName): bool
{
    return preg_match('/^\.[A-Za-z0-9][A-Za-z0-9._-]*$/D', $fileName) === 1;
}

/** Read the first positive billing identifier from an ordered file list. */
function pmssUserBillingDigitsRead(string $home, array $fileNames, bool $requireRootOwner = false): ?string
{
    $home = rtrim($home, '/');
    if ($home === '') {
        return null;
    }

    foreach ($fileNames as $fileName) {
        $fileName = (string) $fileName;
        if (!pmssUserBillingFileNameIsSafe($fileName)) {
            continue;
        }

        $raw = pmssUserBillingDigitsFileRead($home.'/'.$fileName, $requireRootOwner);
        if ($raw !== null) {
            return $raw;
        }
    }

    return null;
}

/** Read the billing platform service ID, falling back to the legacy file name. */
function pmssUserBillingServiceIdDigitsRead(string $home, bool $requireRootOwner = false): ?string
{
    return pmssUserBillingDigitsRead($home, ['.billingServiceId', '.billingId'], $requireRootOwner);
}

/** Read the billing platform client/account ID when provisioned. */
function pmssUserBillingClientIdDigitsRead(string $home, bool $requireRootOwner = false): ?string
{
    return pmssUserBillingDigitsRead($home, ['.billingClientId'], $requireRootOwner);
}

/** Read the billing platform service ID as an integer. */
function pmssUserBillingServiceIdRead(string $home, bool $requireRootOwner = false): int
{
    $raw = pmssUserBillingServiceIdDigitsRead($home, $requireRootOwner);
    return $raw === null ? 0 : (int) $raw;
}

/** Read the billing platform client/account ID as an integer. */
function pmssUserBillingClientIdRead(string $home, bool $requireRootOwner = false): int
{
    $raw = pmssUserBillingClientIdDigitsRead($home, $requireRootOwner);
    return $raw === null ? 0 : (int) $raw;
}

/** Read a service/client identity only from root-owned provisioning state. */
function pmssUserBillingProvisionedDigitsRead(
    string $user,
    string $key,
    string $configDir = '/etc/seedbox/config',
    int $requiredUid = 0
): ?string {
    if (!pmssValidateUsername($user) || !in_array($key, ['billingServiceId', 'billingClientId'], true)
        || is_link($configDir) || is_link($configDir.'/users')) return null;
    $raw = pmssReadRegularFileContentsVerified($configDir.'/users/'.$user.'.json', $requiredUid, 65536);
    $payload = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        $legacy = pmssReadRegularFileContentsVerified(dirname($configDir).'/runtime/users.json', $requiredUid, 1048576);
        $users = is_string($legacy) ? json_decode($legacy, true) : null;
        $records = is_array($users) && is_array($users['users'] ?? null) ? $users['users'] : $users;
        $payload = is_array($records) ? ($records[$user] ?? null) : null;
    }
    if (!is_array($payload)) return null;
    $value = $payload[$key] ?? ($key === 'billingServiceId' ? ($payload['billingId'] ?? null) : null);
    if (!is_int($value) && !is_string($value)) return null;
    $digits = (string) $value;
    $id = pmssUnsignedDecimalIntParse($digits);
    return $id !== null && $id > 0 ? $digits : null;
}

/** Preserve the legacy home identity until a trusted service ID has been written. */
function pmssUserBillingIdentityRead(string $user, string $home, string $key,
    string $configDir = '/etc/seedbox/config', int $requiredUid = 0, ?callable $warn = null,
    bool $legacyRequireRootOwner = true): ?string
{
    if (!in_array($key, ['billingServiceId', 'billingClientId'], true) || !pmssValidateUsername($user)) return null;
    $trusted = pmssUserBillingProvisionedDigitsRead($user, $key, $configDir, $requiredUid);
    if ($trusted !== null) return $trusted;
    // A trusted service ID marks the account as migrated: never mix it with a
    // home-supplied client ID even if the client field has not arrived yet.
    if ($key === 'billingClientId'
        && pmssUserBillingProvisionedDigitsRead($user, 'billingServiceId', $configDir, $requiredUid) !== null) return null;
    // The fallback is deliberately the former root-owned home-file reader.
    $legacy = $key === 'billingServiceId'
        ? pmssUserBillingServiceIdDigitsRead($home, $legacyRequireRootOwner)
        : pmssUserBillingClientIdDigitsRead($home, $legacyRequireRootOwner);
    if ($legacy !== null && $warn !== null) {
        static $warned = [];
        if (!isset($warned[$user])) {
            $warned[$user] = true;
            $warn($user, 'billing id from legacy home file');
        }
    }
    return $legacy;
}
