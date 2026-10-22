<?php
/**
 * Per-user billing identity reader.
 *
 * Service and client IDs come only from the root-owned provisioning store
 * (docs/adr/0152). Billing marker files in the account home are a copy for
 * the account's own tools; root-side readers never consult them.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/identity.php';

/**
 * Read a positive service/client ID from root-owned provisioning state.
 *
 * Order: /etc/seedbox/config/users/<user>.json, then the legacy aggregate
 * /etc/seedbox/runtime/users.json. Returns null when no positive ID is
 * recorded; callers treat that as "no billing identity".
 */
function pmssUserBillingIdentityRead(
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
    // Older store records carry the service ID under the key billingId.
    $value = $payload[$key] ?? ($key === 'billingServiceId' ? ($payload['billingId'] ?? null) : null);
    if (!is_int($value) && !is_string($value)) return null;
    $digits = (string) $value;
    $id = pmssUnsignedDecimalIntParse($digits);
    return $id !== null && $id > 0 ? $digits : null;
}
