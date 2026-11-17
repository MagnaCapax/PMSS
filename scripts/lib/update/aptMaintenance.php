<?php
/** APT archived-release override and fail-soft cache cleaning. @license GPL-3.0-only */

/**
 * Persist the APT override that disables Release timestamp validation.
 */
function pmssAptWriteValidUntilOverride(?callable $logger = null, ?string $path = null): bool
{
    $log = $logger ?: 'logMessage';
    $target = $path ?: pmssResolvePathFromEnv(
        'PMSS_APT_VALID_UNTIL_OVERRIDE_PATH',
        '/etc/apt/apt.conf.d/90ignore-release-date'
    );
    $dir = dirname($target);

    if (!pmssDirEnsureExists($dir, 0755)) {
        $log('[WARN] Unable to create apt.conf.d directory for Release timestamp override: '.$dir);
        return false;
    }

    return pmssWriteManagedPathFile(
        $target,
        "Acquire::Check-Valid-Until \"false\";\n",
        'apt valid-until override',
        $log,
        null,
        null,
        0644,
        '[WARN] Unable to write Release timestamp override: '.$target
    );
}

/**
 * Run `apt-get clean` and log failures without aborting the update.
 *
 * @param callable|null $runner Optional runner returning `array{rc:int,output:string}`.
 */
function pmssAptRunClean(?callable $logger = null, ?callable $runner = null): bool
{
    $log = $logger ?: 'logMessage';
    try {
        if ($runner === null) {
            $output = [];
            $rc = 1;
            @exec(pmssAptDpkgEnvPrefix().' apt-get clean 2>&1', $output, $rc);

            $result = [
                'rc' => (int) $rc,
                'output' => trim(implode("\n", $output)),
            ];
        } else {
            $result = $runner();
        }
    } catch (Throwable $exception) {
        $log('[WARN] apt-get clean runner failed: '.$exception->getMessage());
        return false;
    }

    $rcRaw = is_array($result) && array_key_exists('rc', $result) ? $result['rc'] : null;
    if (!is_int($rcRaw) && !(is_string($rcRaw) && preg_match('/^-?[0-9]+$/', $rcRaw) === 1)) {
        $log('[WARN] apt-get clean runner returned invalid rc; treating as failure');
        return false;
    }

    $rc = (int) $rcRaw;
    $output = (is_array($result) && isset($result['output']) && is_scalar($result['output']))
        ? trim((string) $result['output'])
        : '';
    if ($rc !== 0) {
        $suffix = ($output === '') ? '' : ' ('.$output.')';
        $log('[WARN] apt-get clean failed with rc '.$rc.$suffix);
        return false;
    }

    return true;
}
