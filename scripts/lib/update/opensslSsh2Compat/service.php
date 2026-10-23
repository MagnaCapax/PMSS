<?php
/** OpenSSL/OpenSSH compatibility service validation, recovery and observation. @license GPL-3.0-only */

// Re-deploy the PMSS sshd_config template after package repair paths touch OpenSSH.
function pmssOpenSslSsh2CompatRedeploySshdConfigTemplate(
    string $backupLabel,
    string $backupPrefix,
    string $redeployLabel,
    ?string $missingWarningFunction = null
): void {
    $tmpl = '/etc/seedbox/config/template.sshd_config';
    $live = '/etc/ssh/sshd_config';
    if (file_exists($tmpl)) {
        runStep('Backing up sshd_config before re-deploying PMSS template ('.$backupLabel.')',
            'cp '.escapeshellarg($live).' '.escapeshellarg($live.'.'.$backupPrefix.'-'.date('Ymd-His')));
        runStep('Re-deploying PMSS sshd_config template ('.$redeployLabel.')',
            'cp '.escapeshellarg($tmpl).' '.escapeshellarg($live));
        runStep('Setting sshd_config permissions after template re-deploy',
            'chmod 644 '.escapeshellarg($live));
    } elseif ($missingWarningFunction !== null) {
        logMessage('[WARN] '.$missingWarningFunction.': '.$tmpl.' missing on host; cannot re-deploy template — sshd_config may be at package default (no HostkeyAlgorithms +ssh-rsa)');
    }
}

function pmssOpenSslSsh2CompatVerifySshdConfig(string $functionName, string $logContext, string $exceptionSuffix): void
{
    $sshdT = 1;
    @exec('/usr/sbin/sshd -t 2>&1', $sshdTOut, $sshdT);
    if ($sshdT !== 0) {
        logMessage('[ERROR] '.$functionName.': sshd -t failed '.$logContext.': '.implode(' '.chr(124).' ', array_slice($sshdTOut, 0, 5)));
        throw new RuntimeException($functionName.': '.$exceptionSuffix);
    }
}

function pmssOpenSslSsh2CompatPort22Listening(): bool
{
    return trim((string) @shell_exec("ss -tln 2>/dev/null | grep -q ':22 ' && echo yes || echo no")) === 'yes';
}

// Restart ssh.service and fail closed when port 22 does not return.
function pmssOpenSslSsh2CompatRestartSshAndVerify(
    string $description,
    string $command,
    string $functionName,
    string $portLogContext,
    string $exceptionSuffix
): void {
    runStep($description, $command);
    sleep(2);
    if (!pmssOpenSslSsh2CompatPort22Listening()) {
        logMessage('[ERROR] '.$functionName.': port 22 not listening '.$portLogContext);
        throw new RuntimeException($functionName.': '.$exceptionSuffix);
    }
}

/** Inspect the listener executable without mistaking old per-session forks for a broken master. */
function pmssOpenSslSsh2CompatSshdExeDeleted(): bool
{
    // Prefer ssh.service MainPID so old per-session sshd forks do not cause
    // false positives after a healthy restart. Without MainPID, require at
    // least one sshd process with a non-deleted executable.
    $mainPidRaw = trim((string) @shell_exec("systemctl show -p MainPID --value ssh 2>/dev/null"));
    if ($mainPidRaw !== '' && ctype_digit($mainPidRaw) && (int) $mainPidRaw > 0) {
        $exeLink = @readlink('/proc/'.$mainPidRaw.'/exe');
        return $exeLink !== false && strpos($exeLink, '(deleted)') !== false;
    }
    // No MainPID: one clean process disproves the all-deleted condition.
    $sawAnyPid = false;
    $pidsRaw = trim((string) @shell_exec('pgrep -x sshd 2>/dev/null'));
    foreach (preg_split('/\s+/', $pidsRaw, -1, PREG_SPLIT_NO_EMPTY) as $pid) {
        if (!ctype_digit($pid)) continue;
        $sawAnyPid = true;
        $exeLink = @readlink('/proc/'.$pid.'/exe');
        if ($exeLink !== false && strpos($exeLink, '(deleted)') === false) return false;
    }
    return $sawAnyPid;
}
