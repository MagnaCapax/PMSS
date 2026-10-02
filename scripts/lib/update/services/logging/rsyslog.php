<?php
/**
 * Rsyslog kernel-input flood protection.
 *
 * /etc/rsyslog.conf is distro/operator-owned, so this preserves every foreign
 * line and converges only the exact imklog declaration shipped by Debian.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

const PMSS_RSYSLOG_IMKLOG_STOCK = 'module(load="imklog")';
const PMSS_RSYSLOG_IMKLOG_LIMITED = 'module(load="imklog" RatelimitInterval="10" RatelimitBurst="2000")';

/** Add the PMSS rate limit to one stock Debian imklog declaration. */
function pmssRsyslogKernelInputRateLimitConfig(string $config): string
{
    if (
        strpos($config, PMSS_RSYSLOG_IMKLOG_LIMITED) !== false
        || substr_count($config, PMSS_RSYSLOG_IMKLOG_STOCK) !== 1
    ) {
        return $config;
    }
    return str_replace(PMSS_RSYSLOG_IMKLOG_STOCK, PMSS_RSYSLOG_IMKLOG_LIMITED, $config);
}

/** Validate and atomically converge the stock Debian rsyslog configuration. */
function pmssApplyRsyslogKernelInputRateLimit(?callable $logger = null, ?callable $runner = null): void
{
    $log = $logger ?: 'logMessage';
    $run = $runner ?: 'runStep';
    $target = pmssResolvePathFromEnv('PMSS_RSYSLOG_CONFIG_PATH', '/etc/rsyslog.conf');
    $current = pmssReadRegularFileContents($target);
    if ($current === null) {
        $log('[WARN] Unable to read regular rsyslog configuration: '.$target);
        return;
    }

    $candidateBody = pmssRsyslogKernelInputRateLimitConfig($current);
    if ($candidateBody === $current) {
        $log(strpos($current, PMSS_RSYSLOG_IMKLOG_LIMITED) !== false
            ? '[SKIP] Rsyslog kernel input rate limit already applied'
            : '[WARN] Preserving nonstandard rsyslog imklog configuration; kernel input rate limit not changed');
        return;
    }
    if (!pmssManagedPathIsSafe($target, 'rsyslog configuration', $log)) {
        return;
    }

    $candidatePath = @tempnam(dirname($target), '.pmss-rsyslog-');
    if (
        !is_string($candidatePath)
        || $candidatePath === ''
        || @file_put_contents($candidatePath, $candidateBody) !== strlen($candidateBody)
    ) {
        if (is_string($candidatePath)) {
            @unlink($candidatePath);
        }
        $log('[WARN] Unable to prepare rsyslog configuration candidate');
        return;
    }
    // Never pass a full host configuration to validation if its private mode failed.
    if (!@chmod($candidatePath, 0600)) {
        @unlink($candidatePath);
        $log('[WARN] Unable to secure rsyslog configuration candidate');
        return;
    }
    try {
        $validationRc = $run(
            'Validating rsyslog kernel input rate limit',
            sprintf('rsyslogd -N1 -f %s', escapeshellarg($candidatePath))
        );
    } finally {
        // Validation failures must not leave a copy of the full configuration behind.
        @unlink($candidatePath);
    }
    if ($validationRc !== 0) {
        $log('[WARN] Rsyslog kernel input rate limit candidate failed validation; existing configuration preserved');
        return;
    }

    $backup = pmssCreateManagedPathBackup($target, 'rsyslog configuration', $log, date('YmdHis'));
    if ($backup === '') {
        return;
    }
    if (!pmssReplaceUserFilePreservingMetadata($target, $candidateBody)) {
        $log('[WARN] Unable to install validated rsyslog kernel input rate limit');
        return;
    }
    $log('Applied rsyslog kernel input rate limit (10s/2000 messages; backup '.$backup.')');

    if (pmssSystemdActionSkip(pmssSystemdActionSkipReason(null, true, true), 'Restarting rsyslog to apply kernel input rate limit')) return;
    runStep('Restarting rsyslog to apply kernel input rate limit', 'systemctl restart rsyslog');
}
