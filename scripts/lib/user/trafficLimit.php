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

if (!function_exists('pmssTrafficLimitEnsureStorageDir')) {
    /**
     * Ensure the runtime quota directory exists as a real directory.
     */
    function pmssTrafficLimitEnsureStorageDir(string $path): bool
    {
        if (!function_exists('pmssPathTargetIsSafe') || !pmssPathTargetIsSafe($path, true)) {
            return false;
        }

        if (function_exists('pmssEnsureDir')) {
            return pmssEnsureDir($path, 0700, 'root', 'root') && is_dir($path) && !is_link($path);
        }

        if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
            return false;
        }

        if (!is_dir($path) || is_link($path)) {
            return false;
        }

        return pmssTrafficLimitConvergeFileMode($path, 0700);
    }
}

if (!function_exists('pmssTrafficLimitRemoveGiBFile')) {
    /**
     * Remove a persisted quota file when it is a real regular file.
     */
    function pmssTrafficLimitRemoveGiBFile(string $path): bool
    {
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            return false;
        }

        if (!file_exists($path)) {
            return true;
        }

        if (@unlink($path)) {
            clearstatcache(true, $path);
            return true;
        }

        clearstatcache(true, $path);
        return !file_exists($path) && !is_link($path);
    }
}

if (!function_exists('pmssTrafficLimitConvergeFileMode')) {
    /**
     * Apply the requested mode to a real file or directory, verifying fallback state.
     */
    function pmssTrafficLimitConvergeFileMode(string $path, int $mode): bool
    {
        if ((!is_file($path) && !is_dir($path)) || is_link($path)) {
            return false;
        }

        if (@chmod($path, $mode)) {
            clearstatcache(true, $path);
            return true;
        }

        clearstatcache(true, $path);
        $perms = @fileperms($path);
        return $perms !== false && (($perms & 0777) === ($mode & 0777));
    }
}

if (!function_exists('pmssTrafficLimitResolveCliUserHome')) {
    /**
     * @param mixed $rawUserName
     * @return array{user:string,home:string}|null
     */
    function pmssTrafficLimitResolveCliUserHome($rawUserName, string $usage, ?int &$exitCode = null): ?array
    {
        $exitCode = null;
        $fail = static function (int $rc, string $message) use (&$exitCode): ?array { fwrite(STDERR, $message); $exitCode = $rc; return null; };
        $userName = function_exists('pmssUsernameNormalizeIfValid')
            ? pmssUsernameNormalizeIfValid((string) $rawUserName)
            : null;
        $normalizedRawUserName = function_exists('pmssNormalizeUsername')
            ? pmssNormalizeUsername((string) $rawUserName)
            : strtolower(trim((string) $rawUserName));
        if ($userName === '') {
            return $fail(2, "Error: missing username.\n".$usage."\n");
        }
        if ($userName === null) {
            return $fail(2, "Error: invalid username: {$normalizedRawUserName}\n");
        }
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            return $fail(1, "Error: must run as root.\n");
        }
        $account = function_exists('pmssUserAccountLookup') ? pmssUserAccountLookup($userName) : null;
        $homeDir = is_array($account) && isset($account['dir']) ? (string) $account['dir'] : "/home/{$userName}";
        if (!is_dir($homeDir) || is_link($homeDir)) {
            return $fail(3, "Error: no such user: {$userName}\n");
        }
        return ['user' => $userName, 'home' => $homeDir];
    }
}

if (!function_exists('pmssUserTrafficLimitCli')) {
    function pmssUserTrafficLimitCli(array $argv, ?string $usage = null): int
    {
        if (!function_exists('pmssRequireCli') && is_file(dirname(__DIR__).'/runtime.php')) require_once dirname(__DIR__).'/runtime.php';
        if (!function_exists('pmssRequireCli') || !pmssRequireCli('This script must be run from the command line.', null)) {
            return 1;
        }
        $optionParser = dirname(__DIR__).'/cli/optionParser.php';
        if (!is_file($optionParser)) {
            fwrite(STDERR, "Error: missing CLI option parser.\n");
            return 1;
        }
        require_once $optionParser;
        foreach ([dirname(__DIR__).'/userLifecycle.php', __DIR__.'/log.php'] as $dependency) {
            if (is_file($dependency)) require_once $dependency;
        }
        if (!is_string($usage) || $usage === '') {
            $usage = rtrim(<<<'TEXT'
Usage:
  ./userTrafficLimit.php --user=<username> --limit=<GiB>
  ./userTrafficLimit.php --user=<username> --show
  ./userTrafficLimit.php --user=<username> --unset
  ./userTrafficLimit.php <username> <GiB>

Notes:
  - Limit unit is GiB (monthly quota).
  - Use 0 (or --unset) to remove a limit.
TEXT
            );
        }
        $parsed = pmssParseCliTokens($argv);
        if (pmssCliOption($parsed, 'help', 'h')) {
            echo $usage."\n";
            return 0;
        }
        $userName = (string) pmssCliOption($parsed, 'user', 'u', $parsed['arguments'][0] ?? '');
        $show = (pmssCliOption($parsed, 'show') === true);
        $unset = (pmssCliOption($parsed, 'unset') === true);
        $limitRaw = pmssCliOption($parsed, 'limit', 'l', $parsed['arguments'][1] ?? null);
        $exitCode = null;
        $resolvedUser = pmssTrafficLimitResolveCliUserHome($userName, $usage, $exitCode);
        if ($resolvedUser === null) return $exitCode ?? 1;
        $userName = $resolvedUser['user'];
        $homeDir = $resolvedUser['home'];
        if ($show && $unset) {
            fwrite(STDERR, "Error: --show and --unset are mutually exclusive.\n");
            return 2;
        }
        $runtimeDir = '/etc/seedbox/runtime/trafficLimits';
        $targetModes = [$runtimeDir.'/'.$userName => 0600, $homeDir.'/.trafficLimit' => 0664];
        if ($show) {
            $limit = pmssTrafficLimitReadGiBFile($runtimeDir.'/'.$userName);
            echo "Traffic limit for {$userName}: {$limit} GiB\n";
            return 0;
        }
        if ($unset) $limitRaw = '0';
        $err = null;
        $trafficLimit = pmssTrafficLimitParseGiB($limitRaw, $err);
        if ($trafficLimit === null) {
            fwrite(STDERR, "Error: invalid --limit value (expected integer GiB): ".($err ?: 'invalid')."\n");
            return 2;
        }
        if (!pmssTrafficLimitEnsureStorageDir($runtimeDir)) {
            fwrite(STDERR, "Error: failed to prepare {$runtimeDir}\n");
            return 4;
        }
        if ($trafficLimit === 0) {
            foreach (array_keys($targetModes) as $target) {
                if (!file_exists($target)) continue;
                if (!pmssTrafficLimitRemoveGiBFile($target)) {
                    fwrite(STDERR, "Error: refusing to remove non-file/symlink: {$target}\n");
                    return 4;
                }
            }
            if (function_exists('pmssUserLog')) pmssUserLog($userName, 'traffic limit unset (GiB quota removed)');
            echo "Traffic limit for {$userName} set at 0 GiB\n";
            return 0;
        }
        foreach ($targetModes as $target => $mode) {
            if (!pmssTrafficLimitWriteGiBFile($target, $trafficLimit)) {
                fwrite(STDERR, "Error: failed to write {$target}\n");
                return 4;
            }
            if (!pmssTrafficLimitConvergeFileMode($target, $mode)) {
                fwrite(STDERR, "Error: failed to secure {$target}\n");
                return 4;
            }
        }
        if (function_exists('pmssUserLog')) pmssUserLog($userName, sprintf('traffic limit set to %d GiB (monthly quota)', $trafficLimit));
        echo "Traffic limit for {$userName} set at {$trafficLimit} GiB\n";
        return 0;
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
