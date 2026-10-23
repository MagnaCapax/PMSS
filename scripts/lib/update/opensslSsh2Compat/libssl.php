<?php
/** OpenSSL/OpenSSH compatibility libssl convergence policy. @license GPL-3.0-only */

/**
 * Hold Debian 12 libssl3/openssl at the PECL ssh2-compatible version.
 *
 * When downgrading, converge libssl3, openssl, and the OpenSSH trio in one
 * apt transaction so openssh is downgraded instead of removed. Refs #436/#585.
 */
function pmssHoldLibssl3ForPeclSsh2Compat(?int $distroVersion = null): void
{
    $targetVersion = PMSS_OPENSSL_SSH2_LIBSSL_TARGET;

    if (!pmssOpenSslSsh2CompatCanMutate(__FUNCTION__, $distroVersion)) {
        return;
    }

    $versionQuery = pmssOpenSslSsh2CompatVersionQueryCommand('libssl3');
    $rawVer = trim((string) @shell_exec($versionQuery));
    if ($rawVer === '') {
        logMessage('[SKIP] pmssHoldLibssl3ForPeclSsh2Compat: libssl3 is not installed');
        return;
    }

    $heldList   = (string) @shell_exec('apt-mark showhold 2>/dev/null');

    $needsDowngrade = pmssOpenSslSsh2CompatVersionCompare($rawVer, 'gt', $targetVersion);
    $needsUpgrade   = pmssOpenSslSsh2CompatVersionCompare($rawVer, 'lt', $targetVersion);
    if (pmssOpenSslSsh2CompatVersionCompare($rawVer, 'eq', $targetVersion)
        && pmssOpenSslSsh2CompatPackageHeld($heldList, 'libssl3')
        && pmssOpenSslSsh2CompatPackageHeld($heldList, 'openssl')) {
        logMessage('[SKIP] pmssHoldLibssl3ForPeclSsh2Compat: already at '.$targetVersion.' with libssl3+openssl held');
        return;
    }

    if ($needsUpgrade) {
        if (runStep('Upgrading libssl3/openssl to '.$targetVersion.' (convergence from older version)',
            aptCmd('install -y libssl3='.$targetVersion.' openssl='.$targetVersion)) !== 0) {
            logMessage('[ERROR] pmssHoldLibssl3ForPeclSsh2Compat: upgrade to '.$targetVersion.' failed');
            throw new RuntimeException('Unable to upgrade libssl3/openssl to '.$targetVersion);
        }
    } elseif ($needsDowngrade) {
        $compatSet = pmssOpenSslSsh2CompatPackageVersionSpecs(['libssl3', 'openssl'], $targetVersion)
            .' '.pmssOpenSslSsh2CompatOpenSshPackages(PMSS_OPENSSL_SSH2_OPENSSH_TARGET);
        if (runStep(
            'Converging libssl3/openssl/openssh to the 3.0.17-compatible set',
            aptCmd('install -y --allow-downgrades --allow-change-held-packages '.$compatSet)
        ) !== 0) {
            logMessage('[ERROR] pmssHoldLibssl3ForPeclSsh2Compat: full-set convergence to '.$targetVersion.' failed');
            throw new RuntimeException('Unable to converge libssl3/openssl/openssh to the '.$targetVersion.'-compatible set');
        }
    }

    $postVer = trim((string) @shell_exec($versionQuery));
    if ($postVer === '') {
        logMessage('[ERROR] pmssHoldLibssl3ForPeclSsh2Compat: unable to read libssl3 version after convergence');
        throw new RuntimeException('Unable to read libssl3 version after convergence');
    }
    if (!pmssOpenSslSsh2CompatVersionCompare($postVer, 'eq', $targetVersion)) {
        logMessage('[ERROR] pmssHoldLibssl3ForPeclSsh2Compat: libssl3 remains at '.$postVer.' after convergence attempt');
        throw new RuntimeException('libssl3 convergence failed: expected '.$targetVersion.', got '.$postVer);
    }

    if (runStep('Holding libssl3 + openssl at '.$targetVersion, 'apt-mark hold libssl3 openssl') !== 0) {
        logMessage('[ERROR] pmssHoldLibssl3ForPeclSsh2Compat: apt-mark hold failed');
        throw new RuntimeException('Unable to hold libssl3/openssl after convergence');
    }

    if (!$needsUpgrade && $needsDowngrade) {
        if (pmssOpenSslSsh2CompatPort22Listening()) {
            runStep('Restarting sshd after libssl3 downgrade', '/usr/bin/systemctl restart ssh');
        } else {
            logMessage('[SKIP] pmssHoldLibssl3ForPeclSsh2Compat: SSH port 22 not listening; skipping restart');
        }
    }
}

