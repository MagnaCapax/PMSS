#!/usr/bin/php
<?php
declare(strict_types=1);

/**
 * PMSS system status probe.
 *
 * Aggregates non-destructive checks to highlight runtime readiness. Intended for
 * production hosts; development environments may report WARN for missing
 * packages.
 */

require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/cli/OptionParser.php';

/**
 * Execute a command and return trimmed output.
 */
function pmssExec(string $command): string
{
    $output = @shell_exec($command);
    return $output === null ? '' : trim((string)$output);
}

/**
 * Normalize a status tuple for display.
 */
function renderStatus(array $result): void
{
    $label  = str_pad('['.$result['status'].']', 9);
    $detail = $result['detail'] !== '' ? ' - '.$result['detail'] : '';
    echo $label.$result['name'].$detail.PHP_EOL;
}

$parsed = pmssParseCliTokens($argv);
$format = strtolower((string) pmssCliOption($parsed, 'output', 'o', 'text'));
$jsonFlag = pmssCliOption($parsed, 'json', 'j', false);
$prettyFlag = pmssCliOption($parsed, 'pretty', 'p', false);
$prettyFlag = $prettyFlag !== false && $prettyFlag !== null;
if ($jsonFlag === true || $format === 'json') {
    $format = 'json';
} else {
    $format = 'text';
}

$checks = [];

// Detect OS codename for later comparisons.
$osInfo    = parse_ini_file('/etc/os-release') ?: [];
$codename  = strtolower(trim($osInfo['VERSION_CODENAME'] ?? ''));
$checks[] = (function () use ($codename) {
    if ($codename === '') {
        return ['name' => 'OS codename', 'status' => 'WARN', 'detail' => 'VERSION_CODENAME missing'];
    }
    return ['name' => 'OS codename', 'status' => 'OK', 'detail' => $codename];
})();

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
    $exists = pmssExec('command -v '.escapeshellarg($binary));
    if ($exists === '') {
        $checks[] = [
            'name'   => sprintf('Binary: %s', $binary),
            'status' => 'WARN',
            'detail' => 'Not found in PATH',
        ];
        continue;
    }
    $detail = pmssExec($infoCmd);
    $checks[] = [
        'name'   => sprintf('Binary: %s', $binary),
        'status' => 'OK',
        'detail' => $detail !== '' ? $detail : 'present',
    ];
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
    if (is_dir($path) || is_file($path)) {
        $checks[] = ['name' => $label, 'status' => 'OK', 'detail' => $path];
    } else {
        $checks[] = ['name' => $label, 'status' => 'WARN', 'detail' => $path.' missing'];
    }
}

// Validate sources list contains detected codename if possible.
if ($codename !== '' && is_file('/etc/apt/sources.list')) {
    $sources = file_get_contents('/etc/apt/sources.list');
    if ($sources !== false && stripos($sources, $codename) === false) {
        $checks[] = [
            'name'   => 'Sources codename match',
            'status' => 'WARN',
            'detail' => sprintf("%s not present in sources.list", $codename),
        ];
    } else {
        $checks[] = [
            'name'   => 'Sources codename match',
            'status' => 'OK',
            'detail' => 'sources.list references '.$codename,
        ];
    }
}

// Confirm MediaArea repository package is present so mediainfo can install from apt.
$repoStatus = pmssExec("dpkg-query -W -f='\${Status} \${Version}' repo-mediaarea 2>/dev/null");
if (preg_match('/install ok installed/i', $repoStatus)) {
    $detail = trim(str_ireplace('install ok installed', '', $repoStatus));
    $checks[] = ['name' => 'Package: repo-mediaarea', 'status' => 'OK', 'detail' => $detail !== '' ? $detail : 'installed'];
} else {
    $checks[] = ['name' => 'Package: repo-mediaarea', 'status' => 'WARN', 'detail' => $repoStatus === '' ? 'package missing' : trim($repoStatus)];
}

// Validate virtualenv-managed binaries.
$venvTargets = [
    'Virtualenv: acd_cli binary' => '/opt/acd_cli/bin/acd_cli',
    'Virtualenv: FlexGet binary' => '/opt/flexget/bin/flexget',
    'Virtualenv: pyLoad binary'  => '/opt/pyload/bin/pyload',
];

foreach ($venvTargets as $label => $path) {
    if (is_file($path) && is_executable($path)) {
        $checks[] = ['name' => $label, 'status' => 'OK', 'detail' => $path];
    } else {
        $checks[] = ['name' => $label, 'status' => 'WARN', 'detail' => $path.' missing or not executable'];
    }
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
            $checks[] = ['name' => $label, 'status' => 'OK', 'detail' => sprintf('%s -> %s', $link, $actual)];
        } else {
            $checks[] = ['name' => $label, 'status' => 'WARN', 'detail' => sprintf('%s -> %s (expected %s)', $link, $actual, $expected)];
        }
    } elseif (is_file($link)) {
        $checks[] = ['name' => $label, 'status' => 'WARN', 'detail' => sprintf('%s present but not a symlink', $link)];
    } else {
        $checks[] = ['name' => $label, 'status' => 'WARN', 'detail' => sprintf('%s missing', $link)];
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
    $flags = $prettyFlag ? JSON_PRETTY_PRINT : 0;
    echo json_encode(['checks' => $checks, 'summary' => $summary], $flags).PHP_EOL;
    exit(0);
}

// Render summary banner.
echo "\nPMSS System Check (".date('Y-m-d H:i:s').")\n";
echo str_repeat('-', 60)."\n";

foreach ($checks as $result) {
    renderStatus($result);
}

echo str_repeat('-', 60)."\n";
echo sprintf("Summary: %d OK, %d WARN, %d ERR\n", $summary['ok'], $summary['warn'], $summary['err']);
exit(0);
