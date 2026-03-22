<?php
/**
 * Shared helpers for `systemctl show` slice properties.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

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

/** @param array<int, string> $propertyNames */
function pmssBuildSystemdShowCommand(string $unit, array $propertyNames): string
{
    return 'systemctl show '.escapeshellarg($unit).' -p '.implode(' -p ', $propertyNames).' 2>/dev/null';
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
    $account = function_exists('posix_getpwnam') ? @posix_getpwnam($username) : null;
    $uid = (is_array($account) && isset($account['uid'])) ? (int) $account['uid'] : 0;
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

/**
 * Extract CPU quota percentage from `systemctl show` properties.
 */
function pmssSystemdCpuQuotaPercent(array $properties): ?int
{
    $rawQuota = trim((string) ($properties['CPUQuota'] ?? ''));
    if ($rawQuota !== '' && stripos($rawQuota, 'infinity') === false && strpos($rawQuota, '%') !== false) {
        $quota = (int) round((float) $rawQuota);
        return $quota > 0 ? $quota : null;
    }
    if (!is_numeric($properties['CPUQuotaPerSecUSec'] ?? null) || !is_numeric($properties['CPUQuotaPeriodUSec'] ?? null)) {
        return null;
    }
    $quotaPeriod = (float) $properties['CPUQuotaPeriodUSec'];
    if ($quotaPeriod <= 0.0) {
        return null;
    }
    $quota = (int) round((((float) $properties['CPUQuotaPerSecUSec']) / $quotaPeriod) * 100);
    return $quota > 0 ? $quota : null;
}
