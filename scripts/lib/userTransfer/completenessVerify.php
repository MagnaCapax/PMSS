<?php
/**
 * Post-transfer completeness verification helpers.
 *
 * @license GPL-3.0-only
 */

/**
 * Build a remote size probe script that returns `du -sb` output over SSH.
 */
function pmssUserTransferBuildRemoteSizeProbe(array $cfg): string
{
    $remoteHome = '/home/'.$cfg['remoteUser'].'/';
    $remoteCommand = pmssBuildCommand('du', ['-sb', $remoteHome]);

    return "#!/bin/bash\nset -e\n".pmssUserTransferBuildSshCommand(
        $cfg['remoteUser'],
        ['-o ConnectTimeout=20', '-o NumberOfPasswordPrompts=1']
    ).' '.escapeshellarg($cfg['hostname']).' '.escapeshellarg($remoteCommand)."\n";
}

/**
 * Parse the byte count from `du -sb` output.
 */
function pmssUserTransferParseDuBytes(string $output): ?int
{
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
 * Run a size probe command and return the measured byte count.
 */
function pmssUserTransferMeasureBytes(string $description, string $command): ?int
{
    $rc = runStep($description, $command);
    if ($rc !== 0) {
        logMessage(sprintf('[WARN] Skipping transfer size verification: %s failed (rc=%d)', strtolower($description), $rc));
        return null;
    }

    $stdout = (string) ($GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stdout'] ?? '');
    $bytes = pmssUserTransferParseDuBytes($stdout);
    if ($bytes === null) {
        logMessage(sprintf('[WARN] Skipping transfer size verification: %s returned unreadable output', strtolower($description)));
        return null;
    }

    return $bytes;
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
 * Measure remote/local home sizes and emit an advisory warning for partial copies.
 */
function pmssUserTransferVerifyCompleteness(array $cfg, string $home, string $expectPath, string $remoteSizeScriptPath): void
{
    $remoteBytes = pmssUserTransferMeasureBytes(
        'Measuring remote home size',
        pmssBuildCommand($expectPath, [$remoteSizeScriptPath])
    );
    $localBytes = pmssUserTransferMeasureBytes(
        'Measuring local home size',
        pmssBuildCommand('du', ['-sb', rtrim($home, '/').'/'])
    );

    if ($remoteBytes === null || $localBytes === null) {
        return;
    }

    $warning = pmssUserTransferEvaluateCompleteness($remoteBytes, $localBytes, $cfg['verifyThreshold']);
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
        $localBytes,
        $remoteBytes,
        $cfg['verifyThreshold']
    ));
}
