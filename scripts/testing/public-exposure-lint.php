#!/usr/bin/env php
<?php
/**
 * public-exposure-lint — fail-closed guard for ADR 0150 (no forced public identifiers).
 *
 * A public certificate, a public name or a new listening port is a permanent disclosure:
 * every Let's Encrypt certificate is published in Certificate Transparency logs for good.
 * ADR 0150 says only the end user turns one on, from the Configure page. This lint pins
 * every place in the shipped tree that can request a certificate, build a per-account
 * public name, or open an nginx port, so a new one fails CI instead of shipping silently.
 *
 * Rules (shipped tree only: etc/ and scripts/, tests excluded):
 *   R1  The per-account certificate request flag appears only in the
 *       ADR 0150 Decision 4 opt-in files and the cron that consumes them. The static test
 *       NoForcedCertificateRequestStaticTest pins the same set for the dev suite; this lint
 *       also runs before a push.
 *   R2  certbot / ACME / DNS-update primitives appear only in the pinned files below.
 *   R3  an nginx `listen` uses only the ports PMSS already opens (80, 443, and 844-846 for
 *       the Pikkumies UIs on the host FQDN, ADR 0150 Decision 3) or a loopback address.
 *   R4  a per-account mcx.fi name (`hash('sha256', 'mcx.fi:...')` or a string ending in
 *       `.mcx.fi`) is built only in the pinned files below.
 *   R5  the name derivation functions in nginxUserHosts.php are called only by the pinned
 *       callers below.
 *
 * Adding a file to a pin list is a decision to publish something new. It needs the
 * operator's ruling, recorded in an ADR the operator accepted, not an agent's.
 *
 * Usage: php scripts/testing/public-exposure-lint.php [--root PATH]
 * Exit:  0 clean, 1 violations, 2 usage error.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

$root = realpath(__DIR__ . '/../..');
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--root' && isset($argv[$i + 1])) { $root = realpath($argv[++$i]); }
}
if ($root === false || !is_dir($root . '/scripts')) { fwrite(STDERR, "public-exposure-lint: cannot resolve repo root\n"); exit(2); }

// R1: ADR 0150 Decision 4, the only writers of the certificate request flag, plus its consumer.
$r1Pinned = [
    'etc/skel/www/configureSettings.php',
    'etc/skel/bin/createWebPublicCerts',
    'scripts/lib/user/webCertFlags.php',
    'scripts/cron/webPublicCertsProcess.php',
];
// R2: host certificate setup and renewal (ADR 0039 opt-in path, termination cleanup, package lists).
$r2Pinned = [
    'etc/seedbox/config/root.cron',
    'etc/seedbox/config/template.logrotate.pmss',
    'etc/seedbox/config/template.nginx-site-default-ssl-lets-encrypt',
    'scripts/cron/certbotRenewalCheck.php',
    'scripts/cron/webPublicCertsProcess.php',
    'scripts/lib/certbotRenewal.php',
    'scripts/lib/certbotSetup.php',
    'scripts/lib/nginxConfig/templates.php',
    'scripts/lib/nginxUserHosts.php',
    'scripts/lib/update/dpkg/selections-debian10.txt',
    'scripts/lib/update/dpkg/selections-debian11.txt',
    'scripts/lib/update/dpkg/selections-debian12.txt',
    'scripts/lib/update/dpkg/selections.txt',
    'scripts/lib/user/terminationCleanup.php',
    'scripts/terminateUser.php',
    'scripts/util/setupLetsEncrypt.php',
    'scripts/util/update-step2.php',
];
// R4: the one name derivation (ADR 0040) and the user-run opt-in tool (ADR 0039).
$r4Pinned = [
    'scripts/lib/nginxUserHosts.php',
    'etc/skel/bin/createWebPublicCerts',
    // Transitional: the current per-account Pikkumies UI origins, which ADR 0150 supersedes.
    // Delete a pin when its file stops building the name; the lint then fails on any copy left behind.
    'etc/skel/bin/pmssHermesRuntime.php',
    'etc/skel/www/pikkumiesPanel.php',
    'etc/skel/www/pikkumiesOpen.php',
    // Display only: Welcome shows the account its own service permalink and publishes nothing (ADR 0150 amendment 2026-10-24).
    'etc/skel/www/welcome.php',
];
// R5: callers of the derivation functions (existing ADR 0039/0040 users). ADR 0150 gives the mcx.fi
// records their own decision; this list only stops new callers.
$r5Callers = [
    'scripts/lib/nginxUserHosts.php',
    'scripts/cron/webPublicCertsProcess.php',
    'scripts/terminateUser.php',
    'scripts/lib/nginxConfig/userConfigsGenerate.php',
    'scripts/lib/wireguard/guides.php',
];

$rules = [
    'R1' => ['re' => '/\.request\-web\-certs/', 'pinned' => $r1Pinned,
        'why' => 'requests a public certificate; ADR 0150 allows only the Configure page opt-in'],
    'R2' => ['re' => '/certbot|acme-challenge|\bnsupdate\b|acme\.sh/i', 'pinned' => $r2Pinned,
        'why' => 'certificate or DNS publishing primitive outside the pinned files'],
    'R4' => ['re' => '/[\'"]mcx\.fi:|\.mcx\.fi[\'"]/', 'pinned' => $r4Pinned,
        'why' => 'builds a per-account public mcx.fi name outside the pinned derivation'],
    'R5' => ['re' => '/\b(pmssMcxLabel|pmssNginxUserMcxHostname|pmssNginxUserMcxClusterHostname|pmssWebPublicCertNames)\s*\(/',
        'pinned' => $r5Callers, 'why' => 'calls the per-account public name derivation outside the pinned callers'],
];

$violations = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if (!$file->isFile()) { continue; }
    $rel = substr($file->getPathname(), strlen($root) + 1);
    if (!preg_match('#^(etc|scripts)/#', $rel) || strpos($rel, 'scripts/lib/tests/') === 0 || $rel === 'scripts/testing/public-exposure-lint.php') { continue; }
    $text = @file_get_contents($file->getPathname());
    if ($text === false || strpos(substr($text, 0, 8000), "\0") !== false) { continue; }
    foreach ($rules as $id => $rule) {
        if (in_array($rel, $rule['pinned'], true) || !preg_match($rule['re'], $text)) { continue; }
        foreach (explode("\n", $text) as $n => $line) {
            if (preg_match($rule['re'], $line)) { $violations[] = sprintf('%s %s:%d %s', $id, $rel, $n + 1, $rule['why']); }
        }
    }
    // R3: nginx listen ports — the pinned set or loopback only; a port built from a variable fails too.
    foreach (explode("\n", $text) as $n => $line) {
        // Only an address or port token counts (digit, [ipv6], variable or string concatenation); prose like "listen for" does not.
        if (!preg_match_all('/\blisten\s+((?:\[[^\]]*\]:)?[0-9][^\s;"\']*|[\$\{][^\s;]*|["\']\s*\.[^;]*)/', $line, $m)) { continue; }
        foreach ($m[1] as $addr) {
            if (preg_match('/^(\[::\]:)?(80|443|844|845|846)$/', $addr) || preg_match('/^(127\.0\.0\.1|\[::1\]|localhost|unix):/', $addr)) { continue; }
            $violations[] = sprintf('R3 %s:%d nginx listens on "%s"; a new public port needs an operator-accepted ADR', $rel, $n + 1, $addr);
        }
    }
}

foreach (array_merge($r1Pinned, $r2Pinned, $r4Pinned, $r5Callers) as $pin) {
    if (!is_file($root . '/' . $pin)) { $violations[] = "PIN {$pin} no longer exists; remove it from the pin list"; }
}
$violations = array_values(array_unique($violations));

if ($violations) {
    fwrite(STDERR, "public-exposure-lint: " . count($violations) . " violation(s) (ADR 0150)\n");
    foreach ($violations as $v) { fwrite(STDERR, "  {$v}\n"); }
    exit(1);
}
echo "public-exposure-lint: OK\n";
exit(0);
