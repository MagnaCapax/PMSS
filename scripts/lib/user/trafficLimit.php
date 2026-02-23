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

        if ($raw === null || $raw === false || $raw === true) {
            $error = ($raw === true) ? 'missing value' : 'missing';
            return null;
        }

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

if (!function_exists('pmssTrafficLimitComputeProgressiveCapMbit')) {
    /**
     * Compute progressive post-cap throttling in Mbit based on overage.
     *
     * @param int   $postCapMbit     Base post-cap speed in Mbit.
     * @param float $overagePercent  Percent over the limit (>= 0).
     * @param float $floorPercent    Minimum percent of post-cap speed.
     * @param float $gracePercent    Overage percent before reduction begins.
     * @param int   $minMbit         Absolute minimum in Mbit (FireQOS safety).
     *
     * @return array{effective:int, adjustedOverage:float, floorMbit:int}
     */
    function pmssTrafficLimitComputeProgressiveCapMbit(
        int $postCapMbit,
        float $overagePercent,
        float $floorPercent,
        float $gracePercent,
        int $minMbit = 1
    ): array {
        $postCapMbit = max(0, $postCapMbit);
        if ($postCapMbit === 0) {
            return ['effective' => 0, 'adjustedOverage' => 0.0, 'floorMbit' => 0];
        }

        $overagePercent = max(0.0, $overagePercent);
        $gracePercent = max(0.0, $gracePercent);
        $floorPercent = max(0.0, min(100.0, $floorPercent));

        $adjustedOverage = max(0.0, $overagePercent - $gracePercent);

        $rawEffective = $postCapMbit * (1 - ($adjustedOverage / 100));
        $minMbit = max(0, $minMbit);
        $floorMbit = (int) ceil($postCapMbit * ($floorPercent / 100));
        $floorMbit = min($postCapMbit, max($floorMbit, $minMbit));

        $effective = (int) floor($rawEffective);
        $effective = min($postCapMbit, max($effective, $floorMbit));

        return [
            'effective'       => $effective,
            'adjustedOverage' => $adjustedOverage,
            'floorMbit'       => $floorMbit,
        ];
    }
}
