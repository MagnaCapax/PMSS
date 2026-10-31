<?php
/** Pure numeric and size transforms; loaded by environment.php. @license GPL-3.0-only */

/** Parse numeric scalars without trimming strings or accepting non-finite conversions. */
function pmssFiniteFloat($value): ?float
{
    if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) return null;
    $number = (float) $value;
    return is_finite($number) ? $number : null;
}

/** Accept finite, non-negative numeric scalars from telemetry and policy data. */
function pmssFiniteNonNegativeFloat($value): ?float
{
    $number = pmssFiniteFloat($value);
    return $number !== null && $number >= 0 ? $number : null;
}

/** Parse an untrimmed unsigned decimal only when PHP can represent it exactly. */
function pmssUnsignedDecimalIntParse(string $raw): ?int
{
    if (!ctype_digit($raw)) return null;
    $digits = ltrim($raw, '0');
    $limit = (string) PHP_INT_MAX;
    if (strlen($digits) > strlen($limit)
        || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) return null;
    return (int) $raw;
}

function pmssParseSizeToBytes(string $value, bool $wholeNumberOnly = false, bool $allowBareBinarySuffix = false): ?float
{
    $value = trim($value);
    $numberPattern = $wholeNumberOnly ? '[0-9]+' : '[0-9]+(?:\.[0-9]+)?';
    $suffixPattern = $allowBareBinarySuffix ? '(?:i?B?)?' : '(?:i?B)?';
    if (preg_match('/^('.$numberPattern.')\s*([KMGTPE]?)'.$suffixPattern.'$/i', $value, $matches) !== 1) return null;
    $powers = ['' => 0, 'K' => 1, 'M' => 2, 'G' => 3, 'T' => 4, 'P' => 5, 'E' => 6];
    return (float) $matches[1] * pow(1024, $powers[strtoupper($matches[2])]);
}

function pmssParseSizeToMiB($value): ?int
{
    $raw = trim((string) $value);
    if ($raw === '' || $raw === 'infinity' || $raw === '0') return null;
    if (preg_match('/^[0-9.]+\s*[KMG]?B?$/i', $raw) !== 1) return is_numeric($raw) ? (int) round(((float) $raw) / 1048576) : null;
    $bytes = pmssParseSizeToBytes($raw);
    return $bytes !== null ? (int) round($bytes / 1048576) : null;
}
