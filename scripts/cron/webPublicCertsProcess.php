#!/usr/bin/env php
<?php
/**
 * webPublicCertsProcess — issue opt-in per-name HTTPS certificates on request.
 *
 * Runs under root cron. A customer opts in by running ~/bin/createWebPublicCerts,
 * which drops a `.request-web-certs` flag in their home (they cannot run certbot
 * themselves — it needs root). This job scans for those flags and, for each
 * requesting user, issues ONE certificate covering their single-homed public
 * hostnames as SANs, then regenerates nginx so the user's public vhost serves it
 * (see scripts/lib/nginxUserHosts.php pmssNginxUserSslBlock + docs/adr/0039).
 *
 * Names issued (single-homed only — HTTP-01 validation must land on THIS host):
 *   - <user>.<server-fqdn>            (per-server subdomain, wildcard DNS)
 *   - <sha16-of-serviceid>.mcx.fi     (portable per-service permalink)
 * The cluster permalink (sha256 of client id) is DELIBERATELY excluded: it is a
 * round-robin multi-A record, so an HTTP-01 challenge can be answered by any of
 * the customer's nodes, not reliably this one. Cluster HTTPS is out of scope
 * here (docs/adr/0039).
 *
 * Rate-limit safety: a failed issuance writes `.request-web-certs.failed` with a
 * timestamp; the user is skipped until it ages out, so a broken request cannot
 * hammer Let's Encrypt. Renewal is handled by the existing host-wide certbot
 * cron (/etc/cron.d/certbot) — nothing here schedules renewal.
 *
 * Exit codes: 0 OK/NOOP, 1 environment error.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

const WEBROOT = '/var/www/acme-challenge';
const FAILED_BACKOFF_SECONDS = 21600; // 6h — do not retry a failing request sooner
const LOG_PREFIX = 'webPublicCerts';

if (posix_getuid() !== 0) {
    fwrite(STDERR, "Must run as root.\n");
    exit(1);
}

// Single-run lock — issuance is slow and must not overlap.
$lock = fopen('/run/pmss-web-public-certs.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    // Another run holds the lock; nothing to do.
    exit(0);
}

// A registered ACME account is a prerequisite (the host registers it via
// setupLetsEncrypt). Without it certbot cannot issue non-interactively.
if (!is_dir('/etc/letsencrypt/accounts')) {
    logLine('result=skipped reason=no-acme-account requested=0 issued=0 failed=0');
    exit(0);
}

if (!is_dir(WEBROOT) && !mkdir(WEBROOT, 0755, true) && !is_dir(WEBROOT)) {
    fwrite(STDERR, "Cannot create webroot ".WEBROOT."\n");
    exit(1);
}

$serverFqdn = trim((string) shell_exec('hostname -f'));
if ($serverFqdn === '') {
    fwrite(STDERR, "hostname -f returned nothing\n");
    exit(1);
}

$requested = 0;
$issued = 0;
$failed = 0;
$changed = false;

foreach (glob('/home/*/.request-web-certs') ?: [] as $flag) {
    $home = dirname($flag);
    $user = basename($home);
    // Basic sanity: real home, plausible username.
    if (!is_dir($home) || preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $user) !== 1) {
        continue;
    }

    // Rate-limit backoff: skip a request whose last attempt failed recently.
    $failedMarker = $flag.'.failed';
    if (is_file($failedMarker) && (time() - (int) filemtime($failedMarker)) < FAILED_BACKOFF_SECONDS) {
        continue;
    }

    $requested++;

    $names = [$user.'.'.$serverFqdn];
    $serviceIdRaw = @file_get_contents($home.'/.billingServiceId');
    $serviceId = is_string($serviceIdRaw) ? preg_replace('/\D+/', '', $serviceIdRaw) : '';
    if ($serviceId !== '') {
        $names[] = substr(hash('sha256', 'mcx.fi:service:'.$serviceId), 0, 16).'.mcx.fi';
    }

    $certName = $names[0];
    $args = ['certonly', '--webroot', '-w', WEBROOT, '--cert-name', $certName,
        '-n', '--agree-tos', '--keep-until-expiring'];
    foreach ($names as $n) {
        $args[] = '-d';
        $args[] = $n;
    }
    $cmd = 'certbot '.implode(' ', array_map('escapeshellarg', $args)).' 2>&1';
    exec($cmd, $out, $rc);

    if ($rc === 0) {
        @unlink($flag);
        @unlink($failedMarker);
        $issued++;
        $changed = true;
        logLine('user='.$user.' result=issued names='.implode(',', $names));
    } else {
        @file_put_contents($failedMarker, gmdate('c')." rc=$rc\n");
        $failed++;
        logLine('user='.$user.' result=failed rc='.$rc.' names='.implode(',', $names)
            .' tail='.substr(str_replace("\n", ' ', implode(' ', array_slice($out, -3))), 0, 300));
    }
    $out = [];
}

// Regenerate configs once so newly-issued certs are picked up, and reload nginx.
// Canonical entry point — no per-user config hand-editing.
if ($changed && is_file('/scripts/util/createNginxConfig.php')) {
    exec('/scripts/util/createNginxConfig.php --restart 2>&1', $regenOut, $regenRc);
    logLine('result=regenerated rc='.$regenRc);
}

logLine('result=done requested='.$requested.' issued='.$issued.' failed='.$failed);
exit(0);

/** Structured, timestamped one-line log to stdout (cron redirects to a logfile). */
function logLine(string $msg): void
{
    echo gmdate('c').' '.LOG_PREFIX.' '.$msg."\n";
}
