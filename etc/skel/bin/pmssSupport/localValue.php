<?php
/** Customer-local support dependencies; no operator bootstrap. @license GPL-3.0-only */

require_once __DIR__.'/localRead.php';

/** Local copy of scripts/lib/runtime/values.php::pmssUnsignedDecimalIntParse; preserve its guards and behavior. */
function pmssSupportLocalUnsignedDecimalIntParse(string $raw): ?int
{
    if (!ctype_digit($raw)) return null;
    $digits = ltrim($raw, '0');
    $limit = (string) PHP_INT_MAX;
    if (strlen($digits) > strlen($limit)
        || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) return null;
    return (int) $raw;
}

/** Local copy of scripts/lib/runtime/filesystemHost.php::pmssHostnameIsValid; preserve its guards and behavior. */
function pmssSupportLocalHostnameIsValid(string $hostname, bool $allowIpv4 = true): bool
{
    if ($hostname === '' || preg_match('/\s/', $hostname) || strlen($hostname) > 253) return false;
    if (filter_var($hostname, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) return $allowIpv4;
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/', $hostname) || strpos($hostname, '..') !== false || $hostname[0] === '.' || substr($hostname, -1) === '.') return false;
    foreach (explode('.', $hostname) as $label) if ($label === '' || strlen($label) > 63 || $label[0] === '-' || substr($label, -1) === '-') return false;
    return true;
}

/** Local copy of scripts/lib/log/json.php::pmssJsonDecodeAssoc; preserve its guards and behavior. */
function pmssSupportLocalJsonDecodeAssoc(string $payload): ?array { $decoded = json_decode($payload, true); return is_array($decoded) ? $decoded : null; }

/** Local copy of scripts/lib/user/identity.php::pmssNormalizeUsername; preserve its guards and behavior. */
function pmssSupportLocalNormalizeUsername(string $username): string
{
    return strtolower(trim($username));
}

/** Local copy of scripts/lib/user/identity.php::pmssUsernameIsValid; preserve its guards and behavior. */
function pmssSupportLocalUsernameIsValid(string $username): bool
{
    $normalized = pmssSupportLocalNormalizeUsername($username);
    return $username !== ''
        && $normalized === $username
        && (bool) preg_match('/^[a-z][a-z0-9]{0,7}$/D', $normalized);
}

/** Local copy of scripts/lib/user/identity.php::pmssValidateUsername; preserve its guards and behavior. */
function pmssSupportLocalValidateUsername(string $username): bool
{
    return pmssSupportLocalUsernameIsValid($username);
}

/** Local copy of scripts/lib/user/billingIds.php::pmssUserBillingIdentityRead; preserve its guards and behavior. */
function pmssSupportLocalUserBillingIdentityRead(
    string $user,
    string $key,
    string $configDir = '/etc/seedbox/config',
    int $requiredUid = 0
): ?string {
    if (!pmssSupportLocalValidateUsername($user) || !in_array($key, ['billingServiceId', 'billingClientId'], true)
        || is_link($configDir) || is_link($configDir.'/users')) return null;
    $raw = pmssSupportLocalReadRegularFileContentsVerified($configDir.'/users/'.$user.'.json', $requiredUid, 65536);
    if (($payload = is_string($raw) ? pmssSupportLocalJsonDecodeAssoc($raw) : null) === null) {
        $legacy = pmssSupportLocalReadRegularFileContentsVerified(dirname($configDir).'/runtime/users.json', $requiredUid, 1048576);
        $users = is_string($legacy) ? pmssSupportLocalJsonDecodeAssoc($legacy) : null;
        $records = is_array($users['users'] ?? null) ? $users['users'] : $users;
        $payload = is_array($records) ? ($records[$user] ?? null) : null;
    }
    if (!is_array($payload)) return null;
    // Older store records carry the service ID under the key billingId.
    $value = $payload[$key] ?? ($key === 'billingServiceId' ? ($payload['billingId'] ?? null) : null);
    if (!is_int($value) && !is_string($value)) return null;
    $digits = (string) $value;
    $id = pmssSupportLocalUnsignedDecimalIntParse($digits);
    return $id !== null && $id > 0 ? $digits : null;
}

