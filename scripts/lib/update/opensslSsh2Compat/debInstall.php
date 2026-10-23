<?php
/** Shared dpkg-direct transaction for the two existing OpenSSH repair policies. */

/** Download, validate and install the repair set inside one private-workspace lifecycle. */
function pmssOpenSslSsh2CompatOpenSshInstall(bool $downgrade): void
{
    $target = PMSS_OPENSSL_SSH2_OPENSSH_TARGET;
    $policies = [
        false => [
            'function' => 'pmssHealOpensshServerIfMissing', 'prefix' => 'pmss-openssh-',
            'tempError' => 'cannot create temporary directory for dpkg-direct heal',
            'downloadLabel' => 'Downloading openssh-server/client/sftp + runit-helper for dpkg-direct heal',
            'packages' => pmssOpenSslSsh2CompatOpenSshPackages().' runit-helper',
            'downloadError' => 'apt-get download failed; refusing unsafe fallback',
            'incompleteError' => 'expected openssh-* .deb files were not downloaded',
            'incompleteException' => 'incomplete deb set',
            'installLabel' => 'Installing openssh-server/client/sftp via dpkg-direct (cascade-heal, conf-preserve)',
            'cleanupLabel' => 'Cleaning openssh-direct download cache',
        ],
        true => [
            'function' => 'pmssEnsureOpensshCompatibleWithHeldLibssl3', 'prefix' => 'pmss-openssh-downgrade-',
            'tempError' => 'cannot create temporary directory',
            'downloadLabel' => 'Downloading openssh-server/client/sftp at '.$target.' for libssl3-3.0.17-compat downgrade',
            'packages' => pmssOpenSslSsh2CompatOpenSshPackages($target, true),
            'downloadError' => 'apt-get download failed — target version may have aged out of the repo',
            'incompleteError' => 'incomplete deb set', 'incompleteException' => 'incomplete debs',
            'installLabel' => 'Installing openssh-server/client/sftp '.$target.' via dpkg-direct (conf-preserve, libssl3-3.0.17-compat downgrade)',
            'cleanupLabel' => 'Cleaning openssh-downgrade download cache',
        ],
    ];
    $policy = $policies[$downgrade];
    $function = $policy['function'];
    $tmpDir = pmssCreatePrivateTempDir($policy['prefix']);
    if ($tmpDir === null) {
        logMessage('[ERROR] '.$function.': '.$policy['tempError']);
        throw new RuntimeException($function.': temp dir creation failed');
    }

    // Retain the legacy boundary: unhold occurs after allocation, before the guarded transaction.
    if ($downgrade) {
        runStep('Unholding openssh-* for downgrade (if held)',
            pmssAptDpkgEnvPrefix().' apt-mark unhold '.pmssOpenSslSsh2CompatOpenSshPackages().' 2>/dev/null || true');
    }
    try {
        if (runStep($policy['downloadLabel'], 'cd '.escapeshellarg($tmpDir).' && '
            .pmssAptDpkgEnvPrefix().' apt-get download '.$policy['packages'].' 2>&1') !== 0) {
            logMessage('[ERROR] '.$function.': '.$policy['downloadError']);
            throw new RuntimeException($function.': download failed');
        }
        $debs = pmssOpenSslSsh2CompatDownloadedDebs($tmpDir);
        if (count($debs) < 3) {
            logMessage('[ERROR] '.$function.': '.$policy['incompleteError'].' (got '.count($debs).')');
            throw new RuntimeException($function.': '.$policy['incompleteException']);
        }

        if (!$downgrade) {
            // Heal installs runit-helper first; the downgrade installs every downloaded deb.
            $runitDebs = pmssOpenSslSsh2CompatDebsByPrefix($debs, 'runit-helper');
            if ($runitDebs !== []) {
                runStep('Installing runit-helper via dpkg-direct (openssh-server dep)',
                    dpkgCmd('--force-confdef --force-confold -i '.escapeshellarg($runitDebs[0])));
            }
            $debs = pmssOpenSslSsh2CompatDebsByPrefix($debs, 'openssh');
        }
        // Preserve conffiles in both policies; only heal tolerates an ABI-mismatch install failure.
        if (runStep($policy['installLabel'], pmssOpenSslSsh2CompatDpkgInstallCommand($debs)) !== 0) {
            if ($downgrade) {
                logMessage('[ERROR] '.$function.': dpkg -i failed during downgrade');
                throw new RuntimeException($function.': dpkg -i failed');
            }
            logMessage('[WARN] '.$function.': dpkg -i exited non-zero (likely libssl3 ABI mismatch with held 3.0.17); binary placed on disk — continuing to hold + verify');
        }
    } finally {
        pmssRemovePrivateTempDir($tmpDir, $policy['prefix'], $policy['cleanupLabel']);
    }
}
