<?php
/**
 * Post-transfer completeness verification helpers.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/remoteScripts.php';

/**
 * Build a privacy-safe shell fragment that prints aggregate home statistics.
 */
function pmssUserTransferBuildHomeStatsShell(string $home): string
{
    $home = rtrim($home, '/').'/';
    $lines = [
        'bytes=$(du -sb -- "$home" 2>/dev/null | awk "NR == 1 {print \$1}")',
        'files=$(find "$home" -type f -printf . 2>/dev/null | wc -c | tr -d "[:space:]")',
        'case "$bytes" in ""|*[!0-9]*) exit 1 ;; esac',
        'case "$files" in ""|*[!0-9]*) exit 1 ;; esac',
        'printf "bytes=%s files=%s\n" "$bytes" "$files"',
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

    return "#!/bin/bash\nset -e\n".pmssUserTransferBuildSshCommand(
        $cfg['remoteUser'],
        ['-o ConnectTimeout=20', '-o NumberOfPasswordPrompts=1']
    ).' '.escapeshellarg($cfg['hostname']).' '.escapeshellarg($remoteCommand)."\n";
}

/**
 * Parse the byte count from legacy `du -sb` or aggregate stats output.
 */
function pmssUserTransferParseDuBytes(string $output): ?int
{
    if (preg_match('/\bbytes=([0-9]+)\b/', $output, $matches) === 1) {
        return (int) $matches[1];
    }

    $lines = preg_split('/\r?\n/', trim($output));
    if (!is_array($lines)) {
        return null;
    }

    foreach ($lines as $line) {
        if (preg_match('/^\s*([0-9]+)\b/', $line, $matches) === 1) {
            return (int) $matches[1];
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

    return ['bytes' => (int) $matches[1], 'files' => (int) $matches[2]];
}

/**
 * Build the local target-side aggregate home statistics command.
 */
function pmssUserTransferBuildLocalHomeStatsCommand(string $home): string
{
    return pmssBuildCommand('bash', ['-c', pmssUserTransferBuildHomeStatsShell($home)]);
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
        pmssUserTransferBuildLocalHomeStatsCommand($home)
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
