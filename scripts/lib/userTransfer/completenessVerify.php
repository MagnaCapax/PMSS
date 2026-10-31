<?php
/**
 * Post-transfer completeness verification helpers.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__).'/userLifecycle.php';
require_once __DIR__.'/localUserSafety.php';
require_once __DIR__.'/homeStats.php';

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
