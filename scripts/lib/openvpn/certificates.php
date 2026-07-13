<?php
/**
 * OpenVPN certificate lifecycle helpers.
 *
 * The provisioning utility owns orchestration; these helpers keep certificate
 * parsing and command construction deterministic enough for hermetic tests.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

const PMSS_OPENVPN_SERVER_CERT_RENEWAL_WINDOW_SECONDS = 2592000; // 30 days

/** Return parsed X.509 expiry data for an OpenVPN certificate. */
function pmssOpenvpnCertificateInfo(string $certificatePath): array
{
    if (!is_file($certificatePath) || !is_readable($certificatePath)) {
        return ['state' => 'missing', 'not_after' => null];
    }
    if (!function_exists('openssl_x509_parse')) {
        return ['state' => 'unsupported', 'not_after' => null];
    }

    $pem = @file_get_contents($certificatePath);
    if (!is_string($pem) || trim($pem) === '') {
        return ['state' => 'unreadable', 'not_after' => null];
    }

    $parsed = @openssl_x509_parse($pem);
    if (!is_array($parsed) || !isset($parsed['validTo_time_t'])) {
        return ['state' => 'invalid', 'not_after' => null];
    }

    return ['state' => 'valid', 'not_after' => (int) $parsed['validTo_time_t']];
}

/** Return the certificate notAfter timestamp when it can be parsed. */
function pmssOpenvpnCertificateNotAfterTimestamp(string $certificatePath): ?int
{
    $info = pmssOpenvpnCertificateInfo($certificatePath);
    return $info['state'] === 'valid' ? (int) $info['not_after'] : null;
}

/**
 * Decide whether the server certificate needs a leaf renewal.
 *
 * Missing or unparsable certificates are left to the normal provisioning path;
 * only a readable leaf with a known expiry can trigger automatic renewal.
 */
function pmssOpenvpnServerCertificateRenewalPlan(
    string $certificatePath,
    ?int $now = null,
    int $renewalWindowSeconds = PMSS_OPENVPN_SERVER_CERT_RENEWAL_WINDOW_SECONDS
): array {
    $info = pmssOpenvpnCertificateInfo($certificatePath);
    if ($info['state'] !== 'valid') {
        return [
            'renew' => false,
            'reason' => $info['state'],
            'not_after' => null,
            'seconds_remaining' => null,
        ];
    }

    $now = $now ?? time();
    $notAfter = (int) $info['not_after'];
    $secondsRemaining = $notAfter - $now;
    if ($secondsRemaining <= 0) {
        $reason = 'expired';
    } elseif ($secondsRemaining <= $renewalWindowSeconds) {
        $reason = 'expiring';
    } else {
        $reason = 'valid';
    }

    return [
        'renew' => $reason !== 'valid',
        'reason' => $reason,
        'not_after' => $notAfter,
        'seconds_remaining' => $secondsRemaining,
    ];
}

/** Boolean wrapper for the common fast-path gate. */
function pmssOpenvpnServerCertificateRenewalNeeded(
    string $certificatePath,
    ?int $now = null,
    int $renewalWindowSeconds = PMSS_OPENVPN_SERVER_CERT_RENEWAL_WINDOW_SECONDS
): bool {
    $plan = pmssOpenvpnServerCertificateRenewalPlan($certificatePath, $now, $renewalWindowSeconds);
    return (bool) $plan['renew'];
}

/** Build a bounded tar backup command for the PKI before EasyRSA mutates it. */
function pmssOpenvpnPkiBackupCommand(
    string $easyRsaDir,
    string $backupRoot = '/var/backups/pmss/config/openvpn',
    ?string $timestamp = null
): string {
    if (!pmssOpenvpnAbsolutePathIsSafe($easyRsaDir) || !pmssOpenvpnAbsolutePathIsSafe($backupRoot)) {
        return '';
    }

    $timestamp = $timestamp !== null && preg_match('/^[0-9]{14}$/', $timestamp) === 1
        ? $timestamp
        : gmdate('YmdHis');
    $backupRoot = rtrim($backupRoot, '/');
    $backupPath = $backupRoot.'/'.$timestamp.'__etc_openvpn_easy-rsa_pki.tgz';

    return 'install -d -m 0700 '.escapeshellarg($backupRoot)
        .' && tar -C '.escapeshellarg($easyRsaDir).' -czf '.escapeshellarg($backupPath).' pki'
        .' && chmod 0600 '.escapeshellarg($backupPath);
}

/**
 * Build the EasyRSA leaf-renewal command.
 *
 * Modern EasyRSA uses renew. Older builds fall back to rebuilding only the
 * server leaf material under the existing CA after the PKI backup has succeeded.
 */
function pmssOpenvpnServerCertificateRenewCommand(string $easyRsaDir): string
{
    if (!pmssOpenvpnAbsolutePathIsSafe($easyRsaDir)) {
        return '';
    }

    $script = 'set -e; cd '.escapeshellarg($easyRsaDir).'; '
        .'if ./easyrsa help renew >/dev/null 2>&1; then '
        .'./easyrsa --batch renew server nopass; '
        .'else '
        .'rm -f pki/issued/server.crt pki/private/server.key pki/reqs/server.req; '
        .'./easyrsa --batch build-server-full server nopass; '
        .'fi';

    return 'bash -lc '.escapeshellarg($script);
}

/** Keep command builders from accepting relative, empty, or NUL-bearing paths. */
function pmssOpenvpnAbsolutePathIsSafe(string $path): bool
{
    return $path !== '' && strpos($path, '/') === 0 && strpos($path, "\0") === false;
}
