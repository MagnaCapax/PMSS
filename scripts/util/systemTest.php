#!/usr/bin/env php
<?php
/**
 * Utility script: system Test.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
declare(strict_types=1);

require_once __DIR__.'/../lib/runtime.php';

/**
 * PMSS system status probe.
 *
 * Aggregates non-destructive checks to highlight runtime readiness. Intended for
 * production hosts; development environments may report WARN for missing
 * packages.
 */

require_once __DIR__.'/../lib/cli/optionParser.php';
require_once __DIR__.'/../lib/update/osRelease.php';

/**
 * Build a normalized check result structure.
 *
 * Centralizing this helper keeps the returned array shape consistent and
 * avoids subtle drift when new checks are added over time.
 */
function pmssStatus(string $name, string $status, string $detail = ''): array
{
    return [
        'name'   => $name,
        'status' => $status,
        'detail' => $detail,
    ];
}

$parsed = pmssParseCliTokens($argv ?? ($_SERVER['argv'] ?? []));
$format = strtolower((string) pmssCliOption($parsed, 'output', 'o', 'text'));
$jsonFlag = pmssCliOption($parsed, 'json', 'j', false);
$prettyFlag = pmssCliOption($parsed, 'pretty', 'p', false);
$prettyFlag = $prettyFlag !== false && $prettyFlag !== null;
$format = ($jsonFlag === true || $format === 'json') ? 'json' : 'text';

$checks = [];

// Detect OS codename for later comparisons.
$codename = getDistroCodename();
if ($codename === '') {
    $checks[] = pmssStatus('OS codename', 'WARN', 'VERSION_CODENAME missing');
} else {
    $checks[] = pmssStatus('OS codename', 'OK', $codename);
}

$binaryChecks = [
    'rtorrent' => 'rtorrent -h 2>&1 | head -n 1',
    'nginx'    => 'nginx -v 2>&1',
    'lighttpd' => 'lighttpd -v 2>&1 | head -n 1',
    'php'      => 'php -v 2>&1 | head -n 1',
    'proftpd'  => 'proftpd -v 2>&1 | head -n 1',
    'openvpn'  => 'openvpn --version 2>&1 | head -n 1',
    'tar'      => 'tar --version 2>&1 | head -n 1',
    'pigz'     => 'pigz --version 2>&1 | head -n 1',
    'gpg'      => 'gpg --version 2>&1 | head -n 1',
    'curl'     => 'curl --version 2>&1 | head -n 1',
    'wget'     => 'wget --version 2>&1 | head -n 1',
    'rsync'    => 'rsync --version 2>&1 | head -n 1',
    'python3'  => 'python3 --version 2>&1 | head -n 1',
    'git'      => 'git --version 2>&1 | head -n 1',
    'acd_cli'  => 'acd_cli --version 2>&1 | head -n 1',
    'flexget'  => 'flexget --version 2>&1 | head -n 1',
    'pyload'   => 'pyload --version 2>&1 | head -n 1',
];

foreach ($binaryChecks as $binary => $infoCmd) {
    $exists = trim((string) @shell_exec('command -v '.escapeshellarg($binary)));
    if ($exists === '') {
        $checks[] = pmssStatus('Binary: '.$binary, 'WARN', 'Not found in PATH');
        continue;
    }
    $detail = trim((string) @shell_exec($infoCmd));
    $checks[] = pmssStatus('Binary: '.$binary, 'OK', $detail !== '' ? $detail : 'present');
}

$configPaths = [
    'Apt sources'          => '/etc/apt/sources.list',
    'ProFTPD configuration' => '/etc/proftpd/proftpd.conf',
    'OpenVPN directory'     => '/etc/openvpn',
    'VPN Easy-RSA'          => '/etc/openvpn/easy-rsa',
    'Seedbox localnet'      => '/etc/seedbox/localnet',
    'Nginx directory'       => '/etc/nginx',
];

foreach ($configPaths as $label => $path) {
    $exists = file_exists($path);
    $checks[] = pmssStatus($label, $exists ? 'OK' : 'WARN', $exists ? $path : $path.' missing');
}

// Validate that the config-backed localnet file matches rtorrent expectations.
// rtorrentConfig appends an ipv4_filter.load reference when /etc/seedbox/config/localnet
// is readable; directory or file permission regressions can break that silently.
$localnetConfig = '/etc/seedbox/config/localnet';
if (is_file($localnetConfig)) {
    $issues = [];
    $filePerms = @fileperms($localnetConfig);
    if ($filePerms === false) {
        $issues[] = 'unable to read localnet file permissions';
    } else {
        $mode = $filePerms & 0777;
        if (($mode & 0004) === 0) {
            $issues[] = sprintf(
                '%s mode %o missing world-read (rtorrent users may not read filter)',
                $localnetConfig,
                $mode
            );
        }
    }

    foreach (['/etc/seedbox', '/etc/seedbox/config'] as $dir) {
        if (!is_dir($dir)) {
            $issues[] = $dir.' missing';
            continue;
        }
        $dirPerms = @fileperms($dir);
        if ($dirPerms === false) {
            $issues[] = 'unable to read permissions for '.$dir;
            continue;
        }
        $dirMode = $dirPerms & 0777;
        if (($dirMode & 0001) === 0) {
            $issues[] = sprintf(
                '%s mode %o missing world-exec (users cannot traverse to localnet)',
                $dir,
                $dirMode
            );
        }
    }

    if ($issues) {
        // Treat broken permissions on an existing localnet config as an error:
        // rtorrent relies on this file being readable from unprivileged users.
        $checks[] = pmssStatus('Seedbox localnet (config)', 'ERR', implode('; ', $issues));
    } else {
        $checks[] = pmssStatus('Seedbox localnet (config)', 'OK', $localnetConfig.' readable via 0664 + traversable dirs');
    }
} else {
    $checks[] = pmssStatus('Seedbox localnet (config)', 'WARN', $localnetConfig.' missing');
}

// Validate sources list contains detected codename if possible.
if ($codename !== '' && is_file('/etc/apt/sources.list')) {
    $sources = file_get_contents('/etc/apt/sources.list');
    $matches = $sources !== false && stripos($sources, $codename) !== false;
    $checks[] = pmssStatus(
        'Sources codename match',
        $matches ? 'OK' : 'WARN',
        $matches ? 'sources.list references '.$codename : sprintf('%s not present in sources.list', $codename)
    );
}

// Check OpenVPN client artifacts in /home (profile + CA), following installer naming.
$hostname = trim((string) @file_get_contents('/etc/hostname'));
if ($hostname === '') {
    $checks[] = pmssStatus('OpenVPN client artifacts', 'WARN', 'hostname unknown');
} else {
    $fqdn = strpos($hostname, '.pulsedmedia.com') !== false ? $hostname : $hostname.'.pulsedmedia.com';
    $slug = str_replace('.', '-', $fqdn);
    $ovpn = '/home/openvpn-'.$slug.'.ovpn';
    $crt = '/home/openvpn-'.$slug.'.crt';
    $ok = is_file($ovpn) && is_file($crt);
    if ($ok) {
        $checks[] = pmssStatus('OpenVPN client artifacts', 'OK', basename($ovpn).', '.basename($crt));
    } else {
        $missing = [];
        if (!is_file($ovpn)) { $missing[] = basename($ovpn); }
        if (!is_file($crt))  { $missing[] = basename($crt); }
        $checks[] = pmssStatus('OpenVPN client artifacts', 'WARN', 'missing: '.implode(', ', $missing));
    }
}

// MediaArea repo is configured via manual list + ASCII key; no repo-mediaarea package check.

// Validate virtualenv-managed binaries.
$venvTargets = [
    'Virtualenv: acd_cli binary' => '/opt/acd_cli/bin/acd_cli',
    'Virtualenv: FlexGet binary' => '/opt/flexget/bin/flexget',
    'Virtualenv: pyLoad binary'  => '/opt/pyload/bin/pyload',
];

foreach ($venvTargets as $label => $path) {
    $valid = is_file($path) && is_executable($path);
    $checks[] = pmssStatus(
        $label,
        $valid ? 'OK' : 'WARN',
        $valid ? $path : $path.' missing or not executable'
    );
}

// Confirm CLI symlinks route to the corresponding virtualenv binaries.
$symlinkTargets = [
    'CLI symlink: acd_cli' => ['/usr/local/bin/acd_cli', '/opt/acd_cli/bin/acd_cli'],
    'CLI symlink: flexget' => ['/usr/local/bin/flexget', '/opt/flexget/bin/flexget'],
    'CLI symlink: pyLoad'  => ['/usr/local/bin/pyload', '/opt/pyload/bin/pyload'],
];

foreach ($symlinkTargets as $label => [$link, $expected]) {
    if (is_link($link)) {
        $actual = readlink($link);
        if ($actual === $expected) {
            $checks[] = pmssStatus($label, 'OK', sprintf('%s -> %s', $link, $actual));
        } else {
            $checks[] = pmssStatus($label, 'WARN', sprintf('%s -> %s (expected %s)', $link, $actual, $expected));
        }
    } elseif (is_file($link)) {
        $checks[] = pmssStatus($label, 'WARN', sprintf('%s present but not a symlink', $link));
    } else {
        $checks[] = pmssStatus($label, 'WARN', sprintf('%s missing', $link));
    }
}

// Fold in the componentStatus view so operators get a single, richer picture.
// The helper already focuses on binaries/configs; here we simply import its
// JSON output when available and prefix entries so name clashes remain obvious.
$componentJson = trim((string) @shell_exec('php /scripts/util/componentStatus.php --json 2>/dev/null'));
if ($componentJson !== '') {
    $decoded = json_decode($componentJson, true);
    foreach (is_array($decoded['results'] ?? null) ? $decoded['results'] : [] as $entry) {
        if (!isset($entry['name'], $entry['status'])) {
            continue;
        }
        $name   = 'Component: '.$entry['name'];
        $status = (string)$entry['status'];
        $detail = (string) ($entry['detail'] ?? '');
        $checks[] = pmssStatus($name, $status, $detail);
    }
}

$errors = count(array_filter($checks, static function ($c) { return $c['status'] === 'ERR'; }));
$warnings = count(array_filter($checks, static function ($c) { return $c['status'] === 'WARN'; }));
$summary = [
    'ok'   => count($checks) - $warnings - $errors,
    'warn' => $warnings,
    'err'  => $errors,
];

if ($format === 'json') {
    echo json_encode(['checks' => $checks, 'summary' => $summary], $prettyFlag ? JSON_PRETTY_PRINT : 0).PHP_EOL;
    exit(0);
}

// Render summary banner.
echo "\nPMSS System Check (".date('Y-m-d H:i:s').")\n";
echo str_repeat('-', 60)."\n";

$isTty = function_exists('posix_isatty') ? posix_isatty(STDOUT) : true;
foreach ($checks as $result) {
    $status = strtoupper((string)$result['status']);

    $colour = '';
    $reset  = '';
    if ($isTty) {
        if ($status === 'OK') {
            $colour = "\033[32m"; // green
        } elseif ($status === 'WARN') {
            $colour = "\033[33m"; // yellow
        } elseif ($status === 'ERR') {
            $colour = "\033[31m"; // red
        }
        if ($colour !== '') {
            $reset = "\033[0m";
        }
    }

    $labelPadded = str_pad('['.$status.']', 9);
    echo $colour.$labelPadded.$reset.$result['name'].($result['detail'] !== '' ? ' - '.$result['detail'] : '').PHP_EOL;
}

echo str_repeat('-', 60)."\n";
echo sprintf("Summary: %d OK, %d WARN, %d ERR\n", $summary['ok'], $summary['warn'], $summary['err']);
