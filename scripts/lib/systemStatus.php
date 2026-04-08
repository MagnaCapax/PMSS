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

/**
 * Encode a status payload as JSON without letting invalid UTF-8 break output.
 */
function pmssStatusJsonEncode(array $payload, int $flags = 0): string
{
    if (is_string($json = pmssJsonEncodeSafe($payload, $flags))) {
        return $json;
    }

    return '{"error":"status_json_encode_failed","code":'.(int) json_last_error().'}';
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
    $isTty = $useColour && pmssStreamIsTty(STDOUT, true);
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

/** Emit either JSON or text output for a PMSS status report. */
function pmssStatusEmit(
    array $checks,
    string $title,
    bool $wantJson,
    array $jsonPayload,
    ?array $summary = null,
    int $jsonFlags = 0,
    bool $useColour = false,
    int $labelWidth = 8,
    bool $leadingNewline = true
): int
{
    if ($wantJson) { echo pmssStatusJsonEncode($jsonPayload, $jsonFlags).PHP_EOL; return 0; }
    pmssRenderStatusText($title, $checks, $summary ?? pmssStatusSummary($checks), $useColour, $labelWidth, $leadingNewline);
    return 0;
}

/**
 * Collect the richer system-test probe used by scripts/util/systemTest.php.
 *
 * Dependencies may be overridden in tests to keep the characterization suite
 * hermetic and independent from host state.
 */
function pmssSystemStatusChecks(array $dependencies = []): array
{
    $runCommand = $dependencies['runCommand'] ?? static function (string $command): string { return trim((string) @shell_exec($command)); };
    $pathExists = $dependencies['pathExists'] ?? static function (string $path): bool { return is_dir($path) || is_file($path); };
    $isFile = $dependencies['isFile'] ?? static function (string $path): bool { return is_file($path); };
    $isDir = $dependencies['isDir'] ?? static function (string $path): bool { return is_dir($path); };
    $isExecutable = $dependencies['isExecutable'] ?? static function (string $path): bool { return is_executable($path); };
    $isLink = $dependencies['isLink'] ?? static function (string $path): bool { return is_link($path); };
    $readLink = $dependencies['readLink'] ?? static function (string $path): string {
        $target = readlink($path);
        return $target === false ? '' : (string) $target;
    };
    $readFile = $dependencies['readFile'] ?? static function (string $path): string { $contents = @file_get_contents($path); return $contents === false ? '' : (string) $contents; };
    $filePerms = $dependencies['filePerms'] ?? static function (string $path) { return @fileperms($path); };

    $checks = [];
    $codename = getDistroCodename();
    $checks[] = $codename === ''
        ? pmssStatus('OS codename', 'WARN', 'VERSION_CODENAME missing')
        : pmssStatus('OS codename', 'OK', $codename);

    foreach ([
        'rtorrent' => 'rtorrent -h 2>&1 | head -n 1',
        'nginx' => 'nginx -v 2>&1',
        'lighttpd' => 'lighttpd -v 2>&1 | head -n 1',
        'php' => 'php -v 2>&1 | head -n 1',
        'proftpd' => 'proftpd -v 2>&1 | head -n 1',
        'openvpn' => 'openvpn --version 2>&1 | head -n 1',
        'tar' => 'tar --version 2>&1 | head -n 1',
        'pigz' => 'pigz --version 2>&1 | head -n 1',
        'gpg' => 'gpg --version 2>&1 | head -n 1',
        'curl' => 'curl --version 2>&1 | head -n 1',
        'wget' => 'wget --version 2>&1 | head -n 1',
        'rsync' => 'rsync --version 2>&1 | head -n 1',
        'python3' => 'python3 --version 2>&1 | head -n 1',
        'git' => 'git --version 2>&1 | head -n 1',
        'flexget' => 'flexget --version 2>&1 | head -n 1',
        'pyload' => 'pyload --version 2>&1 | head -n 1',
    ] as $binary => $infoCommand) {
        $path = trim((string) $runCommand('command -v '.escapeshellarg($binary)));
        if ($path === '') {
            $checks[] = pmssStatus('Binary: '.$binary, 'WARN', 'Not found in PATH');
            continue;
        }

        $detail = trim((string) $runCommand($infoCommand));
        $checks[] = pmssStatus('Binary: '.$binary, 'OK', $detail !== '' ? $detail : 'present');
    }

    $sourcesPath = pmssResolvePathFromEnv('PMSS_APT_SOURCES_PATH', '/etc/apt/sources.list');
    foreach ([
        'Apt sources' => $sourcesPath,
        'ProFTPD configuration' => '/etc/proftpd/proftpd.conf',
        'OpenVPN directory' => '/etc/openvpn',
        'VPN Easy-RSA' => '/etc/openvpn/easy-rsa',
        'Seedbox localnet' => '/etc/seedbox/localnet',
        'Nginx directory' => '/etc/nginx',
    ] as $label => $path) {
        $exists = $pathExists($path);
        $checks[] = pmssStatus($label, $exists ? 'OK' : 'WARN', $exists ? $path : $path.' missing');
    }

    $localnetConfig = '/etc/seedbox/config/localnet';
    if ($isFile($localnetConfig)) {
        $issues = [];
        $configPerms = $filePerms($localnetConfig);
        if ($configPerms === false) {
            $issues[] = 'unable to read localnet file permissions';
        } elseif ((($configPerms & 0777) & 0004) === 0) {
            $issues[] = sprintf(
                '%s mode %o missing world-read (rtorrent users may not read filter)',
                $localnetConfig,
                $configPerms & 0777
            );
        }

        foreach (['/etc/seedbox', '/etc/seedbox/config'] as $dir) {
            if (!$isDir($dir)) {
                $issues[] = $dir.' missing';
                continue;
            }

            $dirPerms = $filePerms($dir);
            if ($dirPerms === false) {
                $issues[] = 'unable to read permissions for '.$dir;
                continue;
            }
            if ((($dirPerms & 0777) & 0001) === 0) {
                $issues[] = sprintf(
                    '%s mode %o missing world-exec (users cannot traverse to localnet)',
                    $dir,
                    $dirPerms & 0777
                );
            }
        }

        $checks[] = pmssStatus(
            'Seedbox localnet (config)',
            $issues === [] ? 'OK' : 'ERR',
            $issues === [] ? $localnetConfig.' readable via 0664 + traversable dirs' : implode('; ', $issues)
        );
    } else {
        $checks[] = pmssStatus('Seedbox localnet (config)', 'WARN', $localnetConfig.' missing');
    }

    if ($codename !== '' && $isFile($sourcesPath)) {
        $sources = $readFile($sourcesPath);
        $matches = $sources !== '' && stripos($sources, $codename) !== false;
        $checks[] = pmssStatus(
            'Sources codename match',
            $matches ? 'OK' : 'WARN',
            $matches ? 'sources.list references '.$codename : sprintf('%s not present in sources.list', $codename)
        );
    }

    $hostname = trim((string) $readFile('/etc/hostname'));
    if ($hostname === '') {
        $checks[] = pmssStatus('OpenVPN client artifacts', 'WARN', 'hostname unknown');
    } else {
        $fqdn = strpos($hostname, '.pulsedmedia.com') !== false ? $hostname : $hostname.'.pulsedmedia.com';
        $slug = str_replace('.', '-', $fqdn);
        $ovpn = '/home/openvpn-'.$slug.'.ovpn';
        $crt = '/home/openvpn-'.$slug.'.crt';
        $checks[] = $isFile($ovpn) && $isFile($crt)
            ? pmssStatus('OpenVPN client artifacts', 'OK', basename($ovpn).', '.basename($crt))
            : pmssStatus(
                'OpenVPN client artifacts',
                'WARN',
                'missing: '.implode(', ', array_filter([
                    !$isFile($ovpn) ? basename($ovpn) : '',
                    !$isFile($crt) ? basename($crt) : '',
                ]))
            );
    }

    foreach ([
        'Virtualenv: FlexGet binary' => '/opt/flexget/bin/flexget',
        'Virtualenv: pyLoad binary' => '/opt/pyload/bin/pyload',
    ] as $label => $path) {
        $valid = $isFile($path) && $isExecutable($path);
        $checks[] = pmssStatus($label, $valid ? 'OK' : 'WARN', $valid ? $path : $path.' missing or not executable');
    }

    foreach ([
        'CLI symlink: flexget' => ['/usr/local/bin/flexget', '/opt/flexget/bin/flexget'],
        'CLI symlink: pyLoad' => ['/usr/local/bin/pyload', '/opt/pyload/bin/pyload'],
    ] as $label => $target) {
        $link = $target[0];
        $expected = $target[1];
        if ($isLink($link)) {
            $actual = $readLink($link);
            if ($actual === '') {
                $checks[] = pmssStatus($label, 'WARN', sprintf('%s symlink target unreadable', $link));
                continue;
            }

            $checks[] = pmssStatus(
                $label,
                $actual === $expected ? 'OK' : 'WARN',
                $actual === $expected ? sprintf('%s -> %s', $link, $actual) : sprintf('%s -> %s (expected %s)', $link, $actual, $expected)
            );
            continue;
        }

        $checks[] = pmssStatus($label, 'WARN', $isFile($link) ? sprintf('%s present but not a symlink', $link) : sprintf('%s missing', $link));
    }

    foreach (pmssComponentStatusChecks(['runCommand' => $runCommand, 'pathExists' => $pathExists, 'readFile' => $readFile]) as $entry) {
        $checks[] = pmssStatus('Component: '.(string) $entry['name'], (string) $entry['status'], (string) ($entry['detail'] ?? ''));
    }

    return $checks;
}

/** Collect the shared component-status checks used by both system probes. */
function pmssComponentStatusChecks(array $dependencies = []): array
{
    $runCommand = $dependencies['runCommand'] ?? static function (string $command): string { return trim((string) @shell_exec($command)); };
    $pathExists = $dependencies['pathExists'] ?? static function (string $path): bool { return is_dir($path) || is_file($path); };
    $readFile = $dependencies['readFile'] ?? static function (string $path): string { $contents = @file_get_contents($path); return $contents === false ? '' : (string) $contents; };
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
        $path = trim((string) $runCommand('command -v '.escapeshellarg($binary)));
        $results[] = pmssStatus('bin.'.$binary, $path !== '' ? 'OK' : 'WARN', $path);
    }

    $configPaths = ['config.proftpd' => '/etc/proftpd/proftpd.conf', 'config.openvpn' => '/etc/openvpn', 'config.seedbox.localnet' => '/etc/seedbox/localnet', 'config.nginx' => '/etc/nginx'];
    foreach ($configPaths as $name => $path) {
        $exists = $pathExists($path);
        $results[] = pmssStatus($name, $exists ? 'OK' : 'WARN', $exists ? $path : 'missing');
    }
    return $results;
}
