<?php
/** Integer-setting input policy. Loaded by integerSetting.php.
 * @license GPL-3.0-only
 */

/** @param mixed $raw */
function pmssIntegerSettingParseNonNegative($raw, string $suffix = '', ?string &$error = null): ?int
{
    $error = null;

    if (is_int($raw)) {
        $value = $raw;
    } elseif (is_string($raw)) {
        $trim = trim($raw, " \t\r\n");
        if ($trim === '') {
            $error = 'empty';
            return null;
        }

        $suffixPattern = $suffix === '' ? '' : '(?:\s*'.preg_quote($suffix, '/').')?';
        if (!preg_match('/^([0-9]+)'.$suffixPattern.'$/i', $trim, $matches)) {
            $error = 'invalid format';
            return null;
        }
        // Do not turn an oversized quota into PHP_INT_MAX before persisting it.
        $value = pmssUnsignedDecimalIntParse($matches[1]);
        if ($value === null) {
            $error = 'out of range';
            return null;
        }
    } elseif (is_float($raw)) {
        if (floor($raw) != $raw) {
            $error = 'must be an integer';
            return null;
        }
        // PHP floats cannot represent PHP_INT_MAX exactly on 64-bit hosts.
        if (!is_finite($raw) || $raw >= (float) PHP_INT_MAX) {
            $error = 'out of range';
            return null;
        }
        $value = (int) $raw;
    } else {
        $error = $raw === true ? 'missing value' : (($raw === null || $raw === false) ? 'missing' : 'invalid type');
        return null;
    }

    if ($value < 0) {
        $error = 'must be >= 0';
        return null;
    }

    return $value;
}
