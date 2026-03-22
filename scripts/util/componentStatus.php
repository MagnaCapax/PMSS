#!/usr/bin/env php
<?php
/**
 * Utility script: component Status.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
declare(strict_types=1);

require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/update/osRelease.php';

/**
 * PMSS component status reporter.
 *
 * Examines key binaries and configuration paths to summarise runtime health.
 * Supports machine-readable output via `--json`.
 */

$options = getopt('', ['json']);
$wantJson = isset($options['json']);
$results = [];

// OS codename and sources alignment.
$codename = getDistroCodename();
if ($codename === '') {
    $results[] = ['name' => 'os.codename', 'status' => 'WARN', 'detail' => 'VERSION_CODENAME missing'];
} else {
    $results[] = ['name' => 'os.codename', 'status' => 'OK', 'detail' => $codename];
}

$sourcesPath = '/etc/apt/sources.list';
if (is_file($sourcesPath)) {
    $matches = $codename === '' ? true : stripos((string)file_get_contents($sourcesPath), $codename) !== false;
    $results[] = ['name' => 'apt.sources', 'status' => $matches ? 'OK' : 'WARN', 'detail' => $matches ? 'contains '.$codename : 'codename mismatch'];
} else {
    $results[] = ['name' => 'apt.sources', 'status' => 'WARN', 'detail' => 'missing sources.list'];
}

$binaries = [
    'rtorrent',
    'nginx',
    'php',
    'proftpd',
    'openvpn',
    'curl',
];

foreach ($binaries as $binary) {
    $path = trim((string)@shell_exec('command -v '.escapeshellarg($binary)));
    $status = $path !== '' ? 'OK' : 'WARN';
    $results[] = ['name' => 'bin.'.$binary, 'status' => $status, 'detail' => $path];
}

$paths = [
    'config.proftpd' => '/etc/proftpd/proftpd.conf',
    'config.openvpn' => '/etc/openvpn',
    'config.seedbox.localnet' => '/etc/seedbox/localnet',
    'config.nginx' => '/etc/nginx',
];

foreach ($paths as $name => $path) {
    $exists = is_dir($path) || is_file($path);
    $results[] = ['name' => $name, 'status' => $exists ? 'OK' : 'WARN', 'detail' => $exists ? $path : 'missing'];
}

if ($wantJson) {
    echo json_encode(['generated_at' => date('c'), 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

echo "PMSS Component Status (".date('Y-m-d H:i:s').")\n";
echo str_repeat('-', 60)."\n";
foreach ($results as $entry) {
    $label = str_pad('['.$entry['status'].']', 8);
    $detail = $entry['detail'] !== '' ? ' - '.$entry['detail'] : '';
    echo $label.$entry['name'].$detail.PHP_EOL;
}
echo str_repeat('-', 60)."\n";
$warn = count(array_filter($results, static function ($r) { return $r['status'] === 'WARN'; }));
$err  = count(array_filter($results, static function ($r) { return $r['status'] === 'ERR'; }));
echo sprintf("Summary: %d OK, %d WARN, %d ERR\n", count($results) - $warn - $err, $warn, $err);
