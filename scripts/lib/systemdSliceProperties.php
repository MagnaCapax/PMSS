<?php
/**
 * Shared helpers for `systemctl show` slice properties.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/user/identity.php';

/** @param array<int, string> $propertyNames @return array<string, string> */
function pmssParseSystemdPropertyOutput(array $propertyNames, string $output): array
{
    $properties = array_fill_keys($propertyNames, '');
    foreach (preg_split('/\r?\n/', trim($output)) as $line) {
        $separatorPos = is_string($line) ? strpos($line, '=') : false;
        if ($separatorPos === false) {
            continue;
        }
        $propertyName = substr($line, 0, $separatorPos);
        if (array_key_exists($propertyName, $properties)) {
            $properties[$propertyName] = trim(substr($line, $separatorPos + 1));
        }
    }
    return $properties;
}

/**
 * Build a read-only property query; NUL arguments use the empty-query fallback
 * before shell quoting, so malformed input cannot abort a caller's collection.
 *
 * @param array<int, string> $propertyNames
 */
function pmssBuildSystemdShowCommand(string $unit, array $propertyNames): string
{
    if (strpos($unit, "\0") !== false) {
        return 'printf %s ""';
    }
    $propertyArgs = [];
    foreach ($propertyNames as $propertyName) {
        if (!is_string($propertyName) || $propertyName === '') {
            continue;
        }
        // Reject the whole query instead of collecting a misleading partial set.
        if (strpos($propertyName, "\0") !== false) {
            return 'printf %s ""';
        }
        $propertyArgs[] = '-p '.escapeshellarg($propertyName);
    }

    if ($propertyArgs === []) {
        return 'printf %s ""';
    }

    return 'systemctl show '.escapeshellarg($unit).' '.implode(' ', $propertyArgs).' 2>/dev/null';
}

/** @param array<int, string> $propertyNames @return array<string, string> */
function pmssReadSystemdProperties(string $unit, array $propertyNames): array
{
    $output = @shell_exec(pmssBuildSystemdShowCommand($unit, $propertyNames));
    return pmssParseSystemdPropertyOutput($propertyNames, is_string($output) ? $output : '');
}

/** @param array<string, string> $properties @param array<string, string> $fieldMap @param array<string, int> $defaults @return array<string, int>|null */
function pmssMapSystemdIntProperties(array $properties, array $fieldMap, array $defaults = []): ?array
{
    $values = [];
    foreach ($fieldMap as $propertyName => $outputField) {
        $raw = (string) ($properties[$propertyName] ?? '');
        if (!ctype_digit($raw)) {
            if (!array_key_exists($propertyName, $defaults)) {
                return null;
            }
            $values[$outputField] = (int) $defaults[$propertyName];
            continue;
        }
        // systemd emits unsigned counters; PHP saturates oversized casts at PHP_INT_MAX.
        $digits = ltrim($raw, '0');
        $max = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($max)
            || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            return null;
        }
        $values[$outputField] = (int) $raw;
    }
    return $values;
}

/** @param array<string, string> $fieldMap @param array<string, int> $defaults @return array<string, int>|null */
function pmssReadSystemdIntProperties(string $unit, array $fieldMap, array $defaults = []): ?array
{
    return pmssMapSystemdIntProperties(pmssReadSystemdProperties($unit, array_keys($fieldMap)), $fieldMap, $defaults);
}

/** @param array<int, string> $propertyNames @return array<string, string> */
function pmssReadUserSlicePropertiesByUsername(string $username, array $propertyNames): array
{
    $uid = pmssPasswdEntryPositiveUid(pmssUserAccountLookup($username)) ?? 0;
    return $uid > 0
        ? pmssReadSystemdProperties(sprintf('user-%d.slice', $uid), $propertyNames)
        : pmssParseSystemdPropertyOutput($propertyNames, '');
}

/**
 * Parse plain or device-qualified integer properties.
 */
function pmssSystemdPropertyTrailingInt($value): ?int
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if ($value === '' || $value === '[not set]' || stripos($value, 'infinity') !== false) {
        return null;
    }
    if (preg_match('/(\d+)\s*$/', $value, $matches) !== 1 || strlen($matches[1]) > 15) {
        return null;
    }
    $parsed = (int) $matches[1];
    return ($parsed > 0 && $parsed <= 999999999999999) ? $parsed : null;
}

/** Parse a systemd time span into microseconds; unset or malformed values have no duration. */
function pmssSystemdTimeSpanUsec(string $value): ?float
{
    $value = trim($value);
    if ($value === '' || $value === '[not set]' || strcasecmp($value, 'infinity') === 0) {
        return null;
    }
    if (ctype_digit($value)) {
        return (float) $value;
    }
    if (preg_match('/\A\d+(?:\.\d+)?(?:us|ms|min|s|h)(?:\s+\d+(?:\.\d+)?(?:us|ms|min|s|h))*\z/i', $value) !== 1) {
        return null;
    }

    preg_match_all('/(\d+(?:\.\d+)?)(us|ms|min|s|h)/i', $value, $parts, PREG_SET_ORDER);
    $multipliers = ['us' => 1, 'ms' => 1000, 's' => 1000000, 'min' => 60000000, 'h' => 3600000000];
    $usec = 0.0;
    foreach ($parts as $part) {
        $usec += (float) $part[1] * $multipliers[strtolower($part[2])];
    }
    return is_finite($usec) ? $usec : null;
}

/** Extract CPU quota percentage from `systemctl show` properties. */
function pmssSystemdCpuQuotaPercent(array $properties): ?int
{
    $rawQuota = trim((string) ($properties['CPUQuota'] ?? ''));
    if ($rawQuota !== '' && stripos($rawQuota, 'infinity') === false && strpos($rawQuota, '%') !== false) {
        $quota = (int) round((float) $rawQuota);
        return $quota > 0 ? $quota : null;
    }
    $perSecUsec = pmssSystemdTimeSpanUsec((string) ($properties['CPUQuotaPerSecUSec'] ?? ''));
    if ($perSecUsec === null || $perSecUsec <= 0 || $perSecUsec / 10000 > PHP_INT_MAX) {
        return null;
    }
    // PerSecUSec is allowed CPU time per wall-clock second; the scheduling period is unrelated.
    $quota = (int) round($perSecUsec / 10000);
    return $quota > 0 ? $quota : null;
}
