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

if (is_file(dirname(__DIR__).'/lighttpd/userFileWrite.php')) {
    require_once dirname(__DIR__).'/lighttpd/userFileWrite.php';
}

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

if (!function_exists('pmssTrafficLimitReadGiBFile')) {
    /**
     * Read a persisted GiB quota file, returning 0 for missing or invalid data.
     */
    function pmssTrafficLimitReadGiBFile(string $path): int
    {
        if (!is_file($path) || is_link($path)) {
            return 0;
        }

        $raw = trim((string) @file_get_contents($path));
        if ($raw === '') {
            return 0;
        }

        $error = null;
        $value = pmssTrafficLimitParseGiB($raw, $error);
        return ($value !== null) ? $value : 0;
    }
}

if (!function_exists('pmssTrafficLimitWriteGiBFile')) {
    /**
     * Persist a GiB quota file via the shared atomic file writer.
     */
    function pmssTrafficLimitWriteGiBFile(string $path, int $value): bool
    {
        return $value >= 0
            && function_exists('pmssAtomicWriteFile')
            && pmssAtomicWriteFile($path, (string) $value);
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

if (!function_exists('pmssTrafficLimitDefaultOverageStages')) {
    /**
     * Default tiered post-cap throttling profile.
     *
     * The profile mirrors the historical overage policy request from issue #60.
     * Stage matching is evaluated from highest overage threshold to lowest.
     *
     * @return array<int, array<string, float|int>>
     */
    function pmssTrafficLimitDefaultOverageStages(): array
    {
        return [
            ['overagePercent' => 200.0, 'minOverageGiB' => 0.0,    'capMbit' => 1],
            ['overagePercent' => 125.0, 'minOverageGiB' => 0.0,    'capMbit' => 1],
            ['overagePercent' => 100.0, 'minOverageGiB' => 0.0,    'capMbit' => 10],
            ['overagePercent' => 75.0,  'minOverageGiB' => 5120.0, 'capMbit' => 25],
            ['overagePercent' => 50.0,  'minOverageGiB' => 3072.0, 'capMbit' => 50],
        ];
    }
}

if (!function_exists('pmssTrafficLimitSelectTieredCapMbit')) {
    /**
     * Resolve the active tiered cap from overage stages.
     *
     * Invalid stage entries are ignored so malformed operator overrides do not
     * break enforcement for valid entries.
     *
     * @param float                                                           $overagePercent
     * @param float                                                           $overageGiB
     * @param int                                                             $postCapMbit
     * @param array<int, array<string, float|int|string|bool|array|object>>  $rawStages
     *
     * @return array{effective:int, matched:array<string, float|int>|null}
     */
    function pmssTrafficLimitSelectTieredCapMbit(
        float $overagePercent,
        float $overageGiB,
        int $postCapMbit,
        array $rawStages
    ): array {
        $postCapMbit = max(0, $postCapMbit);
        if ($postCapMbit === 0) {
            return ['effective' => 0, 'matched' => null];
        }

        $overagePercent = max(0.0, $overagePercent);
        $overageGiB = max(0.0, $overageGiB);

        $normalizedStages = [];
        foreach ($rawStages as $index => $stage) {
            if (!is_array($stage) ||
                !isset($stage['overagePercent']) || !is_numeric($stage['overagePercent']) ||
                !isset($stage['capMbit']) || !is_numeric($stage['capMbit'])) {
                continue;
            }

            $stageCapMbit = (int) $stage['capMbit'];
            if ($stageCapMbit <= 0) {
                continue;
            }

            $stageMinOverageGiB = 0.0;
            if (isset($stage['minOverageGiB']) && is_numeric($stage['minOverageGiB'])) {
                $stageMinOverageGiB = max(0.0, (float) $stage['minOverageGiB']);
            }

            $normalizedStages[] = [
                'overagePercent' => max(0.0, (float) $stage['overagePercent']),
                'minOverageGiB'  => $stageMinOverageGiB,
                'capMbit'        => $stageCapMbit,
                'index'          => (int) $index,
            ];
        }

        if (empty($normalizedStages)) {
            return ['effective' => $postCapMbit, 'matched' => null];
        }

        usort(
            $normalizedStages,
            static function (array $left, array $right): int {
                if ($left['overagePercent'] !== $right['overagePercent']) {
                    return ($left['overagePercent'] < $right['overagePercent']) ? 1 : -1;
                }
                if ($left['minOverageGiB'] !== $right['minOverageGiB']) {
                    return ($left['minOverageGiB'] < $right['minOverageGiB']) ? 1 : -1;
                }
                if ($left['index'] === $right['index']) {
                    return 0;
                }
                return ($left['index'] < $right['index']) ? -1 : 1;
            }
        );

        foreach ($normalizedStages as $stage) {
            if ($overagePercent < $stage['overagePercent']) {
                continue;
            }
            if ($overageGiB < $stage['minOverageGiB']) {
                continue;
            }

            $effectiveCapMbit = min($postCapMbit, (int) $stage['capMbit']);
            return [
                'effective' => $effectiveCapMbit,
                'matched'   => [
                    'overagePercent' => (float) $stage['overagePercent'],
                    'minOverageGiB'  => (float) $stage['minOverageGiB'],
                    'capMbit'        => (int) $stage['capMbit'],
                ],
            ];
        }

        return ['effective' => $postCapMbit, 'matched' => null];
    }
}
