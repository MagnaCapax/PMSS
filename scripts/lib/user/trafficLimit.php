<?php
/**
 * Traffic limit helpers.
 *
 * Traffic limits in PMSS are stored as an integer monthly quota in GiB.
 *
 * Persistence paths (written by /scripts/util/userTrafficLimit.php):
 * - /etc/seedbox/runtime/trafficLimits/<user> (consumed by scripts/cron/trafficLimits.php)
 * - /home/<user>/.trafficLimit (user-visible; web UI reads this)
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

if (!function_exists('pmssTrafficLimitParseGiB')) {
    /**
     * Parse a traffic limit value expressed as an integer GiB.
     *
     * Accepts:
     * - int (>= 0)
     * - numeric strings like "500"
     * - optional GiB suffix like "500GiB" (case-insensitive)
     *
     * @param mixed       $raw
     * @param string|null $error Set to a short reason on failure.
     *
     * @return int|null GiB value on success, null on failure.
     */
    function pmssTrafficLimitParseGiB($raw, ?string &$error = null): ?int
    {
        $error = null;

        if ($raw === null || $raw === false) {
            $error = 'missing';
            return null;
        }
        if ($raw === true) {
            $error = 'missing value';
            return null;
        }

        $value = null;
        if (is_int($raw)) {
            $value = $raw;
        } elseif (is_string($raw)) {
            $trim = trim($raw);
            if ($trim === '') {
                $error = 'empty';
                return null;
            }
            if (!preg_match('/^([0-9]+)(?:\\s*GiB)?$/i', $trim, $matches)) {
                $error = 'invalid format';
                return null;
            }
            $value = (int) $matches[1];
        } elseif (is_float($raw)) {
            // Floats are not expected from CLI parsing, but reject fractional values explicitly.
            if (floor($raw) != $raw) {
                $error = 'must be an integer';
                return null;
            }
            $value = (int) $raw;
        } else {
            $error = 'invalid type';
            return null;
        }

        if ($value < 0) {
            $error = 'must be >= 0';
            return null;
        }

        return $value;
    }
}

