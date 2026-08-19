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

/** Add the PMSS rate limit to one stock Debian imklog declaration. */
function pmssRsyslogKernelInputRateLimitConfig(string $config): string
{
    $plain = 'module(load="imklog")';
    $limited = 'module(load="imklog" RatelimitInterval="10" RatelimitBurst="2000")';
    if (strpos($config, $limited) !== false || substr_count($config, $plain) !== 1) {
        return $config;
    }
    return str_replace($plain, $limited, $config);
}

/** Validate and atomically converge the stock Debian rsyslog configuration. */
function pmssApplyRsyslogKernelInputRateLimit(?callable $logger = null, ?callable $runner = null): void
{
    $log = $logger ?: 'logMessage';
    $run = $runner ?: 'runStep';
    $target = pmssResolvePathFromEnv('PMSS_RSYSLOG_CONFIG_PATH', '/etc/rsyslog.conf');
    $plain = 'module(load="imklog")';
    $limited = 'module(load="imklog" RatelimitInterval="10" RatelimitBurst="2000")';
    $current = pmssReadRegularFileContents($target);
    if ($current === null) {
        $log('[WARN] Unable to read regular rsyslog configuration: '.$target);
        return;
    }

    $candidateBody = pmssRsyslogKernelInputRateLimitConfig($current);
    if ($candidateBody === $current) {
        $log(strpos($current, $limited) !== false
            ? '[SKIP] Rsyslog kernel input rate limit already applied'
            : '[WARN] Preserving nonstandard rsyslog imklog configuration; kernel input rate limit not changed');
        return;
    }
    if (substr_count($current, $plain) !== 1 || !pmssManagedPathIsSafe($target, 'rsyslog configuration', $log)) {
        return;
    }

    $candidatePath = @tempnam(dirname($target), '.pmss-rsyslog-');
    if (!is_string($candidatePath) || $candidatePath === '' || @file_put_contents($candidatePath, $candidateBody) === false) {
        if (is_string($candidatePath)) @unlink($candidatePath);
        $log('[WARN] Unable to prepare rsyslog configuration candidate');
        return;
    }
    @chmod($candidatePath, 0600);
    $validationRc = $run(
        'Validating rsyslog kernel input rate limit',
        sprintf('rsyslogd -N1 -f %s', escapeshellarg($candidatePath))
    );
    @unlink($candidatePath);
    if ($validationRc !== 0) {
        $log('[WARN] Rsyslog kernel input rate limit candidate failed validation; existing configuration preserved');
        return;
    }

    $backup = pmssCreateManagedPathBackup($target, 'rsyslog configuration', $log, date('YmdHis'));
    if ($backup === '') return;
    if (!pmssReplaceUserFilePreservingMetadata($target, $candidateBody)) {
        $log('[WARN] Unable to install validated rsyslog kernel input rate limit');
        return;
    }
    $log('Applied rsyslog kernel input rate limit (10s/2000 messages; backup '.$backup.')');

    if (($skipReason = pmssSystemdActionSkipReason(null, true, true)) !== '') {
        pmssLogStatus('SKIP', 'Restarting rsyslog to apply kernel input rate limit ('.$skipReason.')');
        return;
    }
    runStep('Restarting rsyslog to apply kernel input rate limit', 'systemctl restart rsyslog');
}
