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

require_once __DIR__.'/integerSetting.php';

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
        return pmssIntegerSettingParseNonNegative($raw, 'GiB', $error);
    }
}

if (!function_exists('pmssTrafficLimitReadGiBFile')) {
    /**
     * Read a persisted GiB quota file, returning 0 for missing or invalid data.
     */
    function pmssTrafficLimitReadGiBFile(string $path): int
    {
        return pmssIntegerSettingFileRead($path, 'pmssTrafficLimitParseGiB');
    }
}

if (!function_exists('pmssTrafficLimitStateRead')) {
    /** @return array{limitGiB:int,bonusGiB:int,effectiveLimitGiB:int} */
    function pmssTrafficLimitStateRead(string $limitPath, string $bonusPath = ''): array
    {
        $limitGiB = pmssTrafficLimitReadGiBFile($limitPath);
        $bonusGiB = ($bonusPath !== '') ? pmssTrafficLimitReadGiBFile($bonusPath) : 0;

        return ['limitGiB' => $limitGiB, 'bonusGiB' => $bonusGiB, 'effectiveLimitGiB' => ($limitGiB > 0) ? ($limitGiB + $bonusGiB) : 0];
    }
}

if (!function_exists('pmssTrafficLimitWriteGiBFile')) {
    /**
     * Persist a GiB quota file via the shared atomic file writer.
     */
    function pmssTrafficLimitWriteGiBFile(string $path, int $value): bool
    {
        return pmssIntegerSettingFileWrite($path, $value);
    }
}

if (!function_exists('pmssTrafficLimitEnsureStorageDir')) {
    /**
     * Ensure the runtime quota directory exists as a real directory.
     */
    function pmssTrafficLimitEnsureStorageDir(string $path): bool
    {
        return pmssIntegerSettingStorageDirEnsure($path, 0700);
    }
}

if (!function_exists('pmssTrafficLimitRemoveGiBFile')) {
    /**
     * Remove a persisted quota file when it is a real regular file.
     */
    function pmssTrafficLimitRemoveGiBFile(string $path): bool
    {
        return pmssIntegerSettingFileRemove($path);
    }
}

if (!function_exists('pmssTrafficLimitConvergeFileMode')) {
    /**
     * Apply the requested mode to a real file or directory, verifying fallback state.
     */
    function pmssTrafficLimitConvergeFileMode(string $path, int $mode): bool
    {
        return pmssIntegerSettingPathModeConverge($path, $mode);
    }
}

if (!function_exists('pmssTrafficLimitPersistTargetModes')) {
    /** @param array<string,int> $targetModes */
    function pmssTrafficLimitPersistTargetModes(array $targetModes, int $value, ?string &$error = null): bool
    {
        return pmssIntegerSettingTargetModesPersist($targetModes, $value, $error, 'invalid GiB value', true);
    }
}

if (!function_exists('pmssTrafficLimitResolveCliUserHome')) {
    function pmssTrafficLimitCliUsernameNormalize(string $rawUserName): ?string
    {
        if (function_exists('pmssUsernameNormalizeIfValid')) {
            return pmssUsernameNormalizeIfValid($rawUserName);
        }

        $normalized = function_exists('pmssNormalizeUsername')
            ? pmssNormalizeUsername($rawUserName)
            : strtolower(trim($rawUserName));

        return preg_match('/^[a-z][a-z0-9]{0,7}$/D', $normalized) === 1
            ? $normalized
            : null;
    }

    /** @return array<string,mixed>|null */
    function pmssTrafficLimitCliUserAccountLookup(string $userName): ?array
    {
        if (function_exists('pmssUserAccountLookup')) {
            return pmssUserAccountLookup($userName);
        }
        if (function_exists('posix_getpwnam')) {
            $account = @posix_getpwnam($userName);
            return is_array($account) && isset($account['uid']) ? $account : null;
        }

        if (preg_match('/^[a-z][a-z0-9]{0,7}$/D', $userName) !== 1) {
            return null;
        }

        if (($parts = pmssColonRecordFieldsLookup('/etc/passwd', $userName, 7)) !== null) {
            return [
                'name' => (string) $parts[0],
                'uid' => (int) $parts[2],
                'gid' => (int) $parts[3],
                'dir' => (string) $parts[5],
            ];
        }

        return null;
    }

    /**
     * @param mixed $rawUserName
     * @return array{user:string,home:string}|null
     */
    function pmssTrafficLimitResolveCliUserHome($rawUserName, string $usage, ?int &$exitCode = null): ?array
    {
        $exitCode = null;
        $fail = static function (int $rc, string $message) use (&$exitCode): ?array { fwrite(STDERR, $message); $exitCode = $rc; return null; };
        $userName = pmssTrafficLimitCliUsernameNormalize((string) $rawUserName);
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
        $account = pmssTrafficLimitCliUserAccountLookup($userName);
        $homeDir = is_array($account) && isset($account['dir']) ? (string) $account['dir'] : "/home/{$userName}";
        if (!is_dir($homeDir) || is_link($homeDir)) {
            return $fail(3, "Error: no such user: {$userName}\n");
        }
        return ['user' => $userName, 'home' => $homeDir];
    }
}

if (!function_exists('pmssUserTrafficCliBootstrap')) {
    function pmssUserTrafficCliBootstrap(): bool
    {
        if (!function_exists('pmssRequireCli') && is_file(dirname(__DIR__).'/runtime.php')) {
            require_once dirname(__DIR__).'/runtime.php';
        }
        if (!function_exists('pmssRequireCli') || !pmssRequireCli('This script must be run from the command line.', null)) {
            return false;
        }

        $optionParser = dirname(__DIR__).'/cli/optionParser.php';
        if (!is_file($optionParser)) {
            fwrite(STDERR, "Error: missing CLI option parser.\n");
            return false;
        }
        require_once $optionParser;
        $userLogDependency = __DIR__.'/log.php';
        if (!function_exists('pmssUserLog') && is_file($userLogDependency)) {
            require_once $userLogDependency;
        }

        return true;
    }
}

if (!function_exists('pmssUserGiBSettingUsageText')) {
    /** Build the shared CLI usage text for per-user GiB quota commands. */
    function pmssUserGiBSettingUsageText(
        string $scriptName,
        string $valueOption,
        string $unitNote,
        string $removalNote
    ): string {
        return rtrim(<<<TEXT
Usage:
  ./{$scriptName} --user=<username> --{$valueOption}=<GiB>
  ./{$scriptName} --user=<username> --show
  ./{$scriptName} --user=<username> --unset
  ./{$scriptName} <username> <GiB>

Notes:
  - {$unitNote}
  - {$removalNote}
TEXT
        );
    }
}

if (!function_exists('pmssUserGiBSettingCli')) {
    /** @param array<string,mixed> $spec */
    function pmssUserGiBSettingCli(array $argv, array $spec): int
    {
        if (!pmssUserTrafficCliBootstrap()) return 1;
        $usage = isset($spec['usage']) && is_string($spec['usage']) ? $spec['usage'] : '';
        $parsed = pmssParseCliTokens($argv);
        if (pmssCliOption($parsed, 'help', 'h')) { echo $usage."\n"; return 0; }

        $userName = (string) pmssCliOption($parsed, 'user', 'u', $parsed['arguments'][0] ?? '');
        $show = (pmssCliOption($parsed, 'show') === true);
        $unset = (pmssCliOption($parsed, 'unset') === true);
        $valueRaw = pmssCliOption($parsed, (string) $spec['valueOption'], (string) $spec['valueShortOption'], $parsed['arguments'][1] ?? null);

        $exitCode = null;
        $resolvedUser = pmssTrafficLimitResolveCliUserHome($userName, $usage, $exitCode);
        if ($resolvedUser === null) return $exitCode ?? 1;

        $userName = $resolvedUser['user'];
        $homeDir = $resolvedUser['home'];
        if ($show && $unset) {
            fwrite(STDERR, "Error: --show and --unset are mutually exclusive.\n");
            return 2;
        }

        $targetModes = call_user_func($spec['targetModesResolver'], $userName, $homeDir);

        if ($show) {
            echo sprintf("%s for %s: %d GiB\n", $spec['subjectLabel'], $userName, pmssTrafficLimitReadGiBFile((string) array_key_first($targetModes)));
            return 0;
        }

        if ($unset) $valueRaw = '0';

        $error = null;
        $value = pmssTrafficLimitParseGiB($valueRaw, $error);
        if ($value === null) {
            fwrite(STDERR, sprintf("Error: invalid %s value (expected integer GiB): %s\n", $spec['invalidOptionLabel'], $error ?: 'invalid'));
            return 2;
        }

        $prepareTargetModes = $spec['prepareTargetModes'] ?? null;
        if ($prepareTargetModes !== null && !call_user_func($prepareTargetModes, $targetModes)) {
            fwrite(STDERR, (string) ($spec['prepareError'] ?? 'Error: failed to prepare persisted targets')."\n");
            return 4;
        }

        $removingValue = ($value === 0);
        $persistError = null;
        if (!pmssTrafficLimitPersistTargetModes($targetModes, $value, $persistError)) {
            fwrite(STDERR, 'Error: '.($persistError ?: 'failed to persist targets')."\n");
            return 4;
        }

        if (function_exists('pmssUserLog')) {
            pmssUserLog($userName, $removingValue ? (string) $spec['unsetLogMessage'] : sprintf((string) $spec['setLogTemplate'], $value));
        }

        echo sprintf("%s for %s set %s %d GiB\n", $spec['subjectLabel'], $userName, $spec['setPreposition'], $value);
        return 0;
    }
}

if (!function_exists('pmssTrafficLimitCliTargetModes')) {
    /** @return array<string,int> */
    function pmssTrafficLimitCliTargetModes(string $userName, string $homeDir): array
    {
        return [pmssIntegerSettingRuntimeUserPath('trafficLimits', $userName) => 0600, pmssIntegerSettingUserHomePath($userName, '.trafficLimit', $homeDir) => 0664];
    }
}

if (!function_exists('pmssTrafficLimitCliPrepareTargetModes')) {
    /** @param array<string,int> $targetModes */
    function pmssTrafficLimitCliPrepareTargetModes(array $targetModes): bool
    {
        $runtimePath = array_key_first($targetModes);
        return is_string($runtimePath) && $runtimePath !== ''
            && pmssTrafficLimitEnsureStorageDir(dirname($runtimePath));
    }
}

if (!function_exists('pmssUserTrafficLimitCli')) {
    function pmssUserTrafficLimitCli(array $argv, ?string $usage = null): int
    {
        if (!is_string($usage) || $usage === '') {
            $usage = pmssUserGiBSettingUsageText(
                'userTrafficLimit.php',
                'limit',
                'Limit unit is GiB (monthly quota).',
                'Use 0 (or --unset) to remove a limit.'
            );
        }
        return pmssUserGiBSettingCli($argv, [
            'usage'              => $usage,
            'valueOption'        => 'limit',
            'valueShortOption'   => 'l',
            'subjectLabel'       => 'Traffic limit',
            'setPreposition'     => 'at',
            'invalidOptionLabel' => '--limit',
            'setLogTemplate'     => 'traffic limit set to %d GiB (monthly quota)',
            'unsetLogMessage'    => 'traffic limit unset (GiB quota removed)',
            'targetModesResolver' => 'pmssTrafficLimitCliTargetModes',
            'prepareTargetModes' => 'pmssTrafficLimitCliPrepareTargetModes',
            'prepareError' => 'Error: failed to prepare /etc/seedbox/runtime/trafficLimits',
        ]);
    }
}

if (!function_exists('pmssUserBonusTrafficCli')) {
    /**
     * Reuse the shared GiB-setting CLI for per-user bonus traffic.
     */
    function pmssUserBonusTrafficCli(array $argv): int
    {
        return pmssUserGiBSettingCli($argv, [
            'usage'               => pmssUserGiBSettingUsageText(
                'userBonusTraffic.php',
                'bonus',
                'Bonus unit is GiB (monthly quota add-on).',
                'Use 0 (or --unset) to remove the bonus.'
            ),
            'valueOption'         => 'bonus',
            'valueShortOption'    => 'b',
            'subjectLabel'        => 'Bonus traffic',
            'setPreposition'      => 'to',
            'invalidOptionLabel'  => '--bonus',
            'setLogTemplate'      => 'bonus traffic set to %d GiB (monthly add-on)',
            'unsetLogMessage'     => 'bonus traffic unset (GiB add-on removed)',
            'targetModesResolver' => static function (string $userName, string $homeDir): array {
                return [$homeDir.'/.bonusTraffic' => 0664];
            },
        ]);
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
     * Default post-cap throttling profile.
     *
     * @return array<int, array<string, float|int>>
     */
    function pmssTrafficLimitDefaultOverageStages(): array
    {
        return [['overagePercent' => 0.0, 'minOverageGiB' => 0.0, 'capMbit' => 100]];
    }
}

if (!function_exists('pmssTrafficLimitLegacyOverageStages')) {
    /**
     * Return the legacy PMSS-owned tier table shipped before the May 2026 policy.
     *
     * @return array<int, array<string, float|int>>
     */
    function pmssTrafficLimitLegacyOverageStages(): array
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

if (!function_exists('pmssTrafficLimitNormalizeOverageStages')) {
    /**
     * Normalize operator-provided overage stages, dropping malformed rows.
     *
     * @param array<int, array<string, float|int|string|bool|array|object>> $rawStages
     * @return array<int, array{overagePercent:float,minOverageGiB:float,capMbit:int,index:int}>
     */
    function pmssTrafficLimitNormalizeOverageStages(array $rawStages): array
    {
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

        return $normalizedStages;
    }
}

if (!function_exists('pmssTrafficLimitSortOverageStages')) {
    /**
     * Sort normalized overage stages into first-match evaluation order.
     *
     * @param array<int, array{overagePercent:float,minOverageGiB:float,capMbit:int,index:int}> $normalizedStages
     */
    function pmssTrafficLimitSortOverageStages(array &$normalizedStages): void
    {
        usort($normalizedStages, static function (array $left, array $right): int {
            return ($right['overagePercent'] <=> $left['overagePercent'])
                ?: ($right['minOverageGiB'] <=> $left['minOverageGiB'])
                ?: ($left['index'] <=> $right['index']);
        });
    }
}

if (!function_exists('pmssTrafficLimitOverageStagesMatchLegacyDefault')) {
    /**
     * Detect exactly the old PMSS default so generated configs follow new policy.
     *
     * @param array<int, array<string, float|int|string|bool|array|object>> $rawStages
     */
    function pmssTrafficLimitOverageStagesMatchLegacyDefault(array $rawStages): bool
    {
        $candidate = pmssTrafficLimitNormalizeOverageStages($rawStages);
        $legacy = pmssTrafficLimitNormalizeOverageStages(pmssTrafficLimitLegacyOverageStages());
        pmssTrafficLimitSortOverageStages($candidate);
        pmssTrafficLimitSortOverageStages($legacy);

        $stripIndex = static function (array $stage): array {
            unset($stage['index']);
            return $stage;
        };

        return array_map($stripIndex, $candidate) === array_map($stripIndex, $legacy);
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

        $normalizedStages = pmssTrafficLimitNormalizeOverageStages($rawStages);
        if (empty($normalizedStages)) {
            return ['effective' => $postCapMbit, 'matched' => null];
        }

        pmssTrafficLimitSortOverageStages($normalizedStages);

        foreach ($normalizedStages as $stage) {
            if ($overagePercent < $stage['overagePercent']) {
                continue;
            }
            if ($overageGiB < $stage['minOverageGiB']) {
                continue;
            }

            $effectiveCapMbit = min($postCapMbit, (int) $stage['capMbit']);
            unset($stage['index']);

            return ['effective' => $effectiveCapMbit, 'matched' => $stage];
        }

        return ['effective' => $postCapMbit, 'matched' => null];
    }
}
