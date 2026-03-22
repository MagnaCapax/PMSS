<?php
/** Shared status helpers for PMSS system probes.
 * @license GPL-3.0-only
 * @author PMSS Team
 */
declare(strict_types=1);

require_once __DIR__.'/runtime.php';
require_once __DIR__.'/update/osRelease.php';

/** Build a normalized status entry for text and JSON outputs. */
function pmssStatus(string $name, string $status, string $detail = ''): array
{
    return ['name' => $name, 'status' => $status, 'detail' => $detail];
}
/** Count OK/WARN/ERR entries for summary banners and JSON payloads. */
function pmssStatusSummary(array $checks): array
{
    $errors = count(array_filter($checks, static function ($check) { return ($check['status'] ?? '') === 'ERR'; }));
    $warnings = count(array_filter($checks, static function ($check) { return ($check['status'] ?? '') === 'WARN'; }));
    return ['ok' => count($checks) - $warnings - $errors, 'warn' => $warnings, 'err' => $errors];
}
/** Render a standard PMSS status table with optional TTY colours. */
function pmssRenderStatusText(
    string $title,
    array $checks,
    array $summary,
    bool $useColour = false,
    int $labelWidth = 8,
    bool $leadingNewline = true
): void
{
    echo ($leadingNewline ? "\n" : '').$title.' ('.date('Y-m-d H:i:s').")\n";
    echo str_repeat('-', 60)."\n";
    $isTty = $useColour && (function_exists('posix_isatty') ? posix_isatty(STDOUT) : true);
    foreach ($checks as $result) {
        $status = strtoupper((string) ($result['status'] ?? ''));
        $label = str_pad('['.$status.']', $labelWidth);
        $detail = (string) ($result['detail'] ?? '');
        $colour = '';
        $reset = '';
        if ($isTty) {
            if ($status === 'OK') {
                $colour = "\033[32m";
            } elseif ($status === 'WARN') {
                $colour = "\033[33m";
            } elseif ($status === 'ERR') {
                $colour = "\033[31m";
            }
            if ($colour !== '') {
                $reset = "\033[0m";
            }
        }
        echo $colour.$label.$reset.$result['name'].($detail !== '' ? ' - '.$detail : '').PHP_EOL;
    }
    echo str_repeat('-', 60)."\n";
    echo sprintf("Summary: %d OK, %d WARN, %d ERR\n", $summary['ok'], $summary['warn'], $summary['err']);
}
/** Collect the shared component-status checks used by both system probes. */
function pmssComponentStatusChecks(?callable $commandRunner = null, ?callable $pathExists = null, ?callable $readFile = null): array
{
    $runCommand = $commandRunner ?? static function (string $command): string {
        return trim((string) @shell_exec($command));
    };
    $pathExists = $pathExists ?? static function (string $path): bool {
        return is_dir($path) || is_file($path);
    };
    $readFile = $readFile ?? static function (string $path): string {
        $contents = @file_get_contents($path);
        return $contents === false ? '' : (string) $contents;
    };
    $results = [];
    $codename = getDistroCodename();
    $results[] = $codename === ''
        ? pmssStatus('os.codename', 'WARN', 'VERSION_CODENAME missing')
        : pmssStatus('os.codename', 'OK', $codename);
    $sourcesPath = pmssResolvePathFromEnv('PMSS_APT_SOURCES_PATH', '/etc/apt/sources.list');
    if (is_file($sourcesPath)) {
        $sources = $readFile($sourcesPath);
        $matches = $codename === '' || stripos($sources, $codename) !== false;
        $results[] = pmssStatus(
            'apt.sources',
            $matches ? 'OK' : 'WARN',
            $matches ? 'contains '.$codename : 'codename mismatch'
        );
    } else {
        $results[] = pmssStatus('apt.sources', 'WARN', 'missing sources.list');
    }
    foreach (['rtorrent', 'nginx', 'php', 'proftpd', 'openvpn', 'curl'] as $binary) {
        $path = $runCommand('command -v '.escapeshellarg($binary));
        $results[] = pmssStatus('bin.'.$binary, $path !== '' ? 'OK' : 'WARN', $path);
    }

    $configPaths = ['config.proftpd' => '/etc/proftpd/proftpd.conf', 'config.openvpn' => '/etc/openvpn', 'config.seedbox.localnet' => '/etc/seedbox/localnet', 'config.nginx' => '/etc/nginx'];
    foreach ($configPaths as $name => $path) {
        $exists = $pathExists($path);
        $results[] = pmssStatus($name, $exists ? 'OK' : 'WARN', $exists ? $path : 'missing');
    }
    return $results;
}
