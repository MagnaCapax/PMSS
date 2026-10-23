<?php
/**
 * libssl3/openssl/openssh PECL-ssh2 compatibility convergence for update-step2.
 *
 * Stable loader and distro/dry-run gate. Package policy, service recovery and
 * the shared dpkg-direct transaction live in focused modules. Requires
 * environment.php for the existing private-workspace helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/environment.php';

const PMSS_OPENSSL_SSH2_LIBSSL_TARGET = '3.0.17-1~deb12u2';
const PMSS_OPENSSL_SSH2_OPENSSH_TARGET = '1:9.2p1-2+deb12u7';
const PMSS_OPENSSL_SSH2_OPENSSH_PACKAGES = ['openssh-server', 'openssh-client', 'openssh-sftp-server'];

// Shared guard: these repairs are Debian 12-only and must honor dry-run mode.
function pmssOpenSslSsh2CompatCanMutate(string $functionName, ?int $distroVersion): bool
{
    if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
        logMessage('[DRY-RUN] '.$functionName.': skipping all mutations');
        return false;
    }

    $effectiveVersion = pmssDistroVersionFromEnv($distroVersion);
    if ($effectiveVersion !== 12) {
        logMessage('[SKIP] '.$functionName.': not Debian 12 (detected: '.$effectiveVersion.')');
        return false;
    }

    return true;
}

require_once __DIR__.'/opensslSsh2Compat/packages.php';
require_once __DIR__.'/opensslSsh2Compat/service.php';
require_once __DIR__.'/opensslSsh2Compat/debInstall.php';
require_once __DIR__.'/opensslSsh2Compat/libssl.php';
require_once __DIR__.'/opensslSsh2Compat/openssh.php';
