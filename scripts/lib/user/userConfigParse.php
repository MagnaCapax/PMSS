<?php
/** Legacy resource-option parsing; defaults and rejection policy stay with each mode. @license GPL-3.0-only */

require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/userConfigResourceSpecs.php';

/** Prefer an explicit long option over a legacy positional slot. */
function pmssUserConfigCliLegacyValue(array $parsed, string $option, array $args, int $legacyIndex, $default = null) { $value = pmssCliOption($parsed, $option, null, null); return ($value !== null && $value !== true) ? $value : (array_key_exists($legacyIndex, $args) ? $args[$legacyIndex] : $default); }

/** @return array<int,string> List shared long-option names for resource flags. */
function pmssUserConfigCliResourceOptionNames(): array { return array_column(pmssUserConfigCliResourceSpecs(), 'addUserOption'); }

/** @return array<string,mixed> Read shared resource options for creation, explicit updates, or defaulted updates. */
function pmssUserConfigCliResources(array $parsed, array $args, string $indexKey, string $mode): array
{
    $values = [];
    foreach (pmssUserConfigCliResourceSpecs() as $key => $spec) {
        if ($mode === 'explicit') {
            $optionValue = $parsed['options'][$spec['addUserOption']] ?? null;
            $index = $spec[$indexKey] ?? -1;
            $value = $optionValue !== null && $optionValue !== true && $optionValue !== ''
                ? $optionValue : ((isset($spec[$indexKey]) && array_key_exists($index, $args) && $args[$index] !== '') ? $args[$index] : null);
        } else {
            $value = pmssUserConfigCliLegacyValue($parsed, $spec['addUserOption'], $args, (int) ($spec[$indexKey] ?? -1), $mode === 'resolved' ? $spec['default'] : null);
        }
        if ($mode === 'resolved' || ($value !== null && $value !== '')) {
            $values[$key] = $mode !== 'raw' && $spec['parse'] === 'int' && $value !== null ? (int) $value : $value;
        }
    }
    return $values;
}

/** Parse the optional Docker toggle while keeping legacy CLI semantics. */
function pmssUserConfigParseDockerEnabledOption($rawOption): ?bool
{
    if ($rawOption === null) {
        return null;
    }
    if ($rawOption === true || $rawOption === '') {
        throw new InvalidArgumentException('--docker-enabled requires true or false');
    }
    if (pmssValueMatchesNormalized($rawOption, ['true', '1', 'yes', 'on'])) return true;
    if (pmssValueMatchesNormalized($rawOption, ['false', '0', 'no', 'off'])) return false;
    throw new InvalidArgumentException('Invalid --docker-enabled value');
}

/** Parse the optional GPU toggle using the same accepted values as Docker. */
function pmssUserConfigParseGpuEnabledOption($rawOption): ?bool
{
    // Reject malformed bytes/types before the shared parser trims CLI text.
    if ($rawOption !== null && $rawOption !== true
        && (!is_string($rawOption) || strpos($rawOption, "\0") !== false)) {
        throw new InvalidArgumentException('Invalid --gpu-enabled value');
    }
    try {
        return pmssUserConfigParseDockerEnabledOption($rawOption);
    } catch (InvalidArgumentException $exception) {
        throw new InvalidArgumentException(str_replace('--docker-enabled', '--gpu-enabled', $exception->getMessage()));
    }
}

/** Parse the optional worker floor; the lighttpd planner retains its thread cap. */
function pmssUserConfigParseLighttpdMinThreadsOption($rawOption): ?int
{
    if ($rawOption === null) return null;
    if (!is_string($rawOption) || preg_match('/^(0|[1-9][0-9]*)$/D', $rawOption) !== 1
        || ($value = pmssUnsignedDecimalIntParse($rawOption)) === null) {
        throw new InvalidArgumentException('Invalid --lighttpd-min-threads value; expected a non-negative integer');
    }
    return $value;
}

/** Parse the optional torrent throttle while ignoring legacy empty flags. */
function pmssUserConfigCliParseUploadThrottleOption($rawOption, string $negativeMessage = 'Upload throttle must be >= 0'): ?int
{
    if ($rawOption === null || $rawOption === true || $rawOption === '') {
        return null;
    }
    if (!is_numeric($rawOption)) {
        throw new InvalidArgumentException('Invalid --upload-throttle-kib value');
    }
    $value = (int) $rawOption;
    if ($value < 0) {
        throw new InvalidArgumentException($negativeMessage);
    }
    return $value;
}
