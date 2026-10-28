<?php
/**
 * Post-transfer completeness verification helpers.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__).'/userLifecycle.php';
require_once __DIR__.'/localUserSafety.php';
require_once __DIR__.'/remoteScripts.php';

/** Build a target-side check that rejects any non-symlink payload owner mismatch. */
function pmssUserTransferBuildPayloadOwnershipCheckCommand(string $dataPath, int $uid, int $gid): string
{
    $find = sprintf(
        'find %s -not -type l \( -not -uid %d -o -not -gid %d \) -print -quit 2>/dev/null',
        escapeshellarg($dataPath),
        $uid,
        $gid
    );
    $script = 'mismatch=$('.$find.")\n"
        .'find_rc=$?' . "\n"
        .'if [ "$find_rc" -ne 0 ]; then exit "$find_rc"; fi' . "\n"
        .'test -z "$mismatch"';

    return pmssBuildCommand('bash', ['-c', $script]);
}

/** Build a target-user check for directory traversal and one readable payload file. */
function pmssUserTransferBuildPayloadAccessCheckShell(string $dataPath): string
{
    $quotedPath = escapeshellarg($dataPath);
    // su keeps root's working directory; find exits 1 when it cannot return to one the account cannot read.
    return 'cd / || exit 1' . "\n"
        .'sample=$(find '.$quotedPath.' -type f -print -quit 2>/dev/null)' . "\n"
        .'find_rc=$?' . "\n"
        .'if [ "$find_rc" -ne 0 ]; then exit "$find_rc"; fi' . "\n"
        .'if [ -n "$sample" ]; then test -r "$sample"; else test -x '.$quotedPath.'; fi' . "\n";
}

/** Hard-fail a cross-username transfer when its payload is not target-accessible. */
function pmssUserTransferVerifyPayloadOwnership(string $user, string $home): void
{
    $dataPath = rtrim($home, '/').'/data';
    if (is_link($dataPath)) {
        throw new RuntimeException('Transferred data path is an unsafe symlink', 1);
    }
    if (!is_dir($dataPath)) {
        logMessage('[INFO] Skipping transferred data ownership verification: data directory is absent');
        return;
    }
    if (!pmssUserTransferIsPathWithinHome($dataPath, $home)) {
        throw new RuntimeException('Transferred data path escapes the user home', 1);
    }

    $account = pmssUserAccountLookup($user);
    if (!is_array($account) || !isset($account['uid'], $account['gid'])) {
        throw new RuntimeException('Unable to resolve target user ownership metadata', 1);
    }

    $ownershipRc = runStep(
        'Verifying transferred data ownership',
        pmssUserTransferBuildPayloadOwnershipCheckCommand($dataPath, (int) $account['uid'], (int) $account['gid'])
    );
    if ($ownershipRc !== 0) {
        throw new RuntimeException('Transferred data ownership verification failed', 1);
    }

    $accessRc = runStep(
        'Verifying transferred data access',
        pmssBuildUserShellCommand($user, pmssUserTransferBuildPayloadAccessCheckShell($dataPath), '/bin/sh')
    );
    if ($accessRc !== 0) {
        throw new RuntimeException('Transferred data access verification failed', 1);
    }

    logMessage('[OK] Transferred data ownership and access verified');
}

/**
 * Build a privacy-safe shell fragment that prints aggregate home statistics.
 */
function pmssUserTransferBuildHomeStatsShell(string $home): string
{
    $home = rtrim($home, '/').'/';
    $lines = [
        // Partial scans must fail even when a later pipeline stage succeeds.
        'set -o pipefail',
        'bytes=$(du -sbx -- "$home" 2>/dev/null | awk "NR == 1 {print \$1}") || exit 1',
        'files=$(find "$home" -xdev -type f -printf . 2>/dev/null | wc -c | tr -d "[:space:]") || exit 1',
        'case "$bytes" in ""|*[!0-9]*) exit 1 ;; esac',
        'case "$files" in ""|*[!0-9]*) exit 1 ;; esac',
        'printf "bytes=%s files=%s\n" "$bytes" "$files"',
        // Resolve the home's device from its longest containing mount. Never emit paths.
        <<<'SHELL'
awk -v home="$home" '
    $5 == "/" || index(home, $5 "/") == 1 {
        if (length($5) > longest) { longest = length($5); device = $3 }
    }
    index($5, home) == 1 {
        for (i = 7; i < NF; i++) if ($i == "-") {
            devices[++n] = $3; types[n] = $(i + 1); break
        }
    }
    END {
        if (device == "") exit 1
        for (i = 1; i <= n; i++) if (devices[i] != device) {
            count++
            if (types[i] ~ /^[a-zA-Z0-9_.-]+$/) totals[types[i]]++
        }
        printf "nested_mounts=%d", count
        for (type in totals) printf " mount_type_%s=%d", type, totals[type]
        printf "\n"
    }
' /proc/self/mountinfo 2>/dev/null || printf 'nested_mounts=unavailable\n'
SHELL
    ];

    return 'home='.escapeshellarg($home)."\n".implode("\n", $lines)."\n";
}

/**
 * Build a remote size/count probe script that emits aggregate values only.
 */
function pmssUserTransferBuildRemoteSizeProbe(array $cfg): string
{
    $remoteHome = '/home/'.$cfg['remoteUser'].'/';
    $remoteCommand = pmssBuildCommand('bash', ['-c', pmssUserTransferBuildHomeStatsShell($remoteHome)]);

    return "#!/bin/bash\nset -e\n".pmssUserTransferBuildSshProbeCommand($cfg, $remoteCommand)."\n";
}

/**
 * Parse the byte count from legacy `du -sb` or aggregate stats output.
 */
function pmssUserTransferParseDuBytes(string $output): ?int
{
    if (preg_match('/\bbytes=([0-9]+)\b/', $output, $matches) === 1) {
        return pmssUnsignedDecimalIntParse($matches[1]);
    }

    foreach (pmssTextLines(trim($output)) as $line) {
        if (preg_match('/^\s*([0-9]+)\b/', $line, $matches) === 1) {
            return pmssUnsignedDecimalIntParse($matches[1]);
        }
    }

    return null;
}

/**
 * Parse aggregate home statistics from the privacy-safe probe output.
 */
function pmssUserTransferParseHomeStats(string $output): ?array
{
    if (preg_match('/\bbytes=([0-9]+)\s+files=([0-9]+)\b/', trim($output), $matches) !== 1) {
        return null;
    }

    // Reject unrepresentable command counters before PHP can saturate the cast.
    $bytes = pmssUnsignedDecimalIntParse($matches[1]);
    $files = pmssUnsignedDecimalIntParse($matches[2]);
    return $bytes === null || $files === null ? null : ['bytes' => $bytes, 'files' => $files];
}

/**
 * Run an aggregate home statistics probe.
 */
function pmssUserTransferMeasureHomeStats(string $description, string $command): ?array
{
    $rc = runStep($description, $command);
    if ($rc !== 0) {
        logMessage(sprintf('[WARN] Skipping transfer home statistics: %s failed (rc=%d)', strtolower($description), $rc));
        return null;
    }

    $stdout = (string) ($GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stdout'] ?? '');
    $stats = pmssUserTransferParseHomeStats($stdout);
    if ($stats === null) {
        logMessage(sprintf('[WARN] Skipping transfer home statistics: %s returned unreadable output', strtolower($description)));
        return null;
    }

    // Only aggregate tokens from the source may reach the persistent transfer log.
    if (preg_match('/^(nested_mounts=(?:[0-9]+|unavailable)(?: mount_type_[a-zA-Z0-9_.-]+=[0-9]+)*)\r?$/m', $stdout, $mounts) === 1) {
        logMessage('[INFO] '.$description.': '.$mounts[1]);
    }

    return $stats;
}

/**
 * Compare remote/local sizes and return advisory details when the local copy is short.
 */
function pmssUserTransferEvaluateCompleteness(int $remoteBytes, int $localBytes, int $verifyThreshold): ?array
{
    if ($remoteBytes <= 0) {
        return null;
    }

    $localPercent = ($localBytes / $remoteBytes) * 100;
    if ($localPercent >= $verifyThreshold) {
        return null;
    }

    return [
        'remoteBytes' => $remoteBytes,
        'localBytes' => $localBytes,
        'verifyThreshold' => $verifyThreshold,
        'localPercent' => round($localPercent, 2),
    ];
}

/**
 * Measure remote/local home stats and emit the existing size advisory.
 */
function pmssUserTransferVerifyCompleteness(array $cfg, string $home, string $expectPath, string $remoteSizeScriptPath): void
{
    $remoteStats = pmssUserTransferMeasureHomeStats(
        'Measuring remote home size',
        pmssBuildCommand($expectPath, [$remoteSizeScriptPath])
    );
    $localStats = pmssUserTransferMeasureHomeStats(
        'Measuring local home size',
        pmssBuildCommand('bash', ['-c', pmssUserTransferBuildHomeStatsShell($home)])
    );

    if ($remoteStats === null || $localStats === null) {
        return;
    }

    logMessage(sprintf(
        '[INFO] Transfer aggregate summary: source_bytes=%d target_bytes=%d source_files=%d target_files=%d',
        $remoteStats['bytes'],
        $localStats['bytes'],
        $remoteStats['files'],
        $localStats['files']
    ));

    $warning = pmssUserTransferEvaluateCompleteness($remoteStats['bytes'], $localStats['bytes'], $cfg['verifyThreshold']);
    if ($warning !== null) {
        logMessage(sprintf(
            '[WARN] Transfer size verification advisory: local=%d bytes remote=%d bytes threshold=%d%% observed=%.2f%%',
            $warning['localBytes'],
            $warning['remoteBytes'],
            $warning['verifyThreshold'],
            $warning['localPercent']
        ));
        return;
    }

    logMessage(sprintf(
        '[INFO] Transfer size verification ok: local=%d bytes remote=%d bytes threshold=%d%%',
        $localStats['bytes'],
        $remoteStats['bytes'],
        $cfg['verifyThreshold']
    ));
}
