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
 * Resolve a binary probe path only when `command -v` yields an executable path.
 */
function pmssStatusBinaryPathResolve(string $binary, callable $runCommand, ?callable $isExecutable = null): string
{
    $binary = trim($binary);
    if ($binary === '' || !pmssCommandBinaryNameIsSafe($binary)) {
        return '';
    }

    $path = trim((string) $runCommand('command -v '.escapeshellarg($binary)));
    if ($path === '' || strpos($path, '/') !== 0) {
        return '';
    }

    return $isExecutable !== null && !$isExecutable($path) ? '' : $path;
}
/** Count OK/WARN/ERR entries for summary banners and JSON payloads. */
function pmssStatusSummary(array $checks): array
{
    $statuses = array_count_values(array_map(static function ($check) { return is_array($check) ? (string) ($check['status'] ?? '') : ''; }, $checks));
    $errors = (int) ($statuses['ERR'] ?? 0); $warnings = (int) ($statuses['WARN'] ?? 0);
    return ['ok' => count($checks) - $warnings - $errors, 'warn' => $warnings, 'err' => $errors];
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
    if ($wantJson) {
        return pmssJsonEmitPayload($jsonPayload, 'Failed to encode status JSON.', $jsonFlags);
    }

    $summary = $summary ?? pmssStatusSummary($checks);
    echo ($leadingNewline ? "\n" : '').$title.' ('.date('Y-m-d H:i:s').")\n";
    echo str_repeat('-', 60)."\n";

    $isTty = $useColour && pmssStreamIsTty(STDOUT, true);
    $textValue = static function ($value): string {
        return is_scalar($value) || $value === null ? (string) $value : '';
    };
    foreach ($checks as $result) {
        $status = strtoupper($textValue($result['status'] ?? ''));
        $label = str_pad('['.$status.']', $labelWidth);
        $name = $textValue($result['name'] ?? '');
        $detail = $textValue($result['detail'] ?? '');
        $colour = $isTty ? (['OK' => "\033[32m", 'WARN' => "\033[33m", 'ERR' => "\033[31m"][$status] ?? '') : '';
        $reset = $colour === '' ? '' : "\033[0m";
        echo $colour.$label.$reset.$name.($detail !== '' ? ' - '.$detail : '').PHP_EOL;
    }

    echo str_repeat('-', 60)."\n";
    echo sprintf(
        "Summary: %d OK, %d WARN, %d ERR\n",
        (int) ($summary['ok'] ?? 0),
        (int) ($summary['warn'] ?? 0),
        (int) ($summary['err'] ?? 0)
    );
    return 0;
}

/** @return array<string,array<int|string,mixed>> Shared probe catalog for system/component status reports. */
function pmssStatusProbeSpecs(string $sourcesPath): array
{
    return [
        'binaries' => [
            'rtorrent' => ['infoCommand' => 'rtorrent -h 2>&1 | head -n 1', 'componentName' => 'bin.rtorrent'], 'nginx' => ['infoCommand' => 'nginx -v 2>&1', 'componentName' => 'bin.nginx'],
            'lighttpd' => ['infoCommand' => 'lighttpd -v 2>&1 | head -n 1'], 'php' => ['infoCommand' => 'php -v 2>&1 | head -n 1', 'componentName' => 'bin.php'],
            'proftpd' => ['infoCommand' => 'proftpd -v 2>&1 | head -n 1', 'componentName' => 'bin.proftpd'], 'openvpn' => ['infoCommand' => 'openvpn --version 2>&1 | head -n 1', 'componentName' => 'bin.openvpn'],
            'tar' => ['infoCommand' => 'tar --version 2>&1 | head -n 1'], 'pigz' => ['infoCommand' => 'pigz --version 2>&1 | head -n 1'],
            'gpg' => ['infoCommand' => 'gpg --version 2>&1 | head -n 1'], 'curl' => ['infoCommand' => 'curl --version 2>&1 | head -n 1', 'componentName' => 'bin.curl'],
            'wget' => ['infoCommand' => 'wget --version 2>&1 | head -n 1'], 'rsync' => ['infoCommand' => 'rsync --version 2>&1 | head -n 1'],
            'python3' => ['infoCommand' => 'python3 --version 2>&1 | head -n 1'], 'git' => ['infoCommand' => 'git --version 2>&1 | head -n 1'],
            'flexget' => ['infoCommand' => 'flexget --version 2>&1 | head -n 1'], 'pyload' => ['infoCommand' => 'pyload --version 2>&1 | head -n 1'],
        ],
        'paths' => [
            ['systemLabel' => 'Apt sources', 'path' => $sourcesPath], ['systemLabel' => 'ProFTPD configuration', 'componentName' => 'config.proftpd', 'path' => '/etc/proftpd/proftpd.conf'],
            ['systemLabel' => 'OpenVPN directory', 'componentName' => 'config.openvpn', 'path' => '/etc/openvpn'], ['systemLabel' => 'VPN Easy-RSA', 'path' => '/etc/openvpn/easy-rsa'],
            ['systemLabel' => 'Seedbox localnet', 'componentName' => 'config.seedbox.localnet', 'path' => '/etc/seedbox/localnet'], ['systemLabel' => 'Nginx directory', 'componentName' => 'config.nginx', 'path' => '/etc/nginx'],
        ],
    ];
}

/** @return array<int, array<string, string>> Walk the shared status probe catalog once. */
function pmssStatusCollectProbeChecks(array $probeSpecs, callable $binaryProbe, callable $pathProbe): array
{
    $checks = [];
    $binarySpecs = isset($probeSpecs['binaries']) && is_array($probeSpecs['binaries'])
        ? $probeSpecs['binaries']
        : [];
    foreach ($binarySpecs as $binary => $binarySpec) {
        ($check = $binaryProbe((string) $binary, $binarySpec)) !== null && $checks[] = $check;
    }
    $pathSpecs = isset($probeSpecs['paths']) && is_array($probeSpecs['paths'])
        ? $probeSpecs['paths']
        : [];
    foreach ($pathSpecs as $pathSpec) {
        ($check = $pathProbe($pathSpec)) !== null && $checks[] = $check;
    }
    return $checks;
}

/** Render a shared binary probe in either system or component view. */
function pmssStatusBinaryProbeCheck(string $binary, array $binarySpec, callable $runCommand, callable $isExecutable, bool $componentView = false): ?array
{
    if ($componentView && !isset($binarySpec['componentName'])) return null;
    $path = pmssStatusBinaryPathResolve($binary, $runCommand, $isExecutable);
    if ($componentView) return pmssStatus((string) $binarySpec['componentName'], $path !== '' ? 'OK' : 'WARN', $path);
    if ($path === '') return pmssStatus('Binary: '.$binary, 'WARN', 'Not found in PATH');
    $infoCommand = isset($binarySpec['infoCommand']) && is_string($binarySpec['infoCommand'])
        ? trim($binarySpec['infoCommand'])
        : '';
    if ($infoCommand === '') {
        return pmssStatus('Binary: '.$binary, 'OK', 'present');
    }

    $detail = trim((string) $runCommand($infoCommand));
    return pmssStatus('Binary: '.$binary, 'OK', $detail !== '' ? $detail : 'present');
}

function pmssStatusContextResolve(array $dependencies = []): array
{
    $sourcesPath = pmssResolvePathFromEnv('PMSS_APT_SOURCES_PATH', '/etc/apt/sources.list');
    return [
        'runCommand' => $dependencies['runCommand'] ?? static function (string $command): string { return trim((string) @shell_exec($command)); },
        'pathExists' => $dependencies['pathExists'] ?? static function (string $path): bool { return is_dir($path) || is_file($path); },
        'readFile' => $dependencies['readFile'] ?? static function (string $path): string { $contents = @file_get_contents($path); return $contents === false ? '' : (string) $contents; },
        'isFile' => $dependencies['isFile'] ?? static function (string $path): bool { return is_file($path); },
        'isDir' => $dependencies['isDir'] ?? static function (string $path): bool { return is_dir($path); },
        'isExecutable' => $dependencies['isExecutable'] ?? static function (string $path): bool { return is_executable($path); },
        'isLink' => $dependencies['isLink'] ?? static function (string $path): bool { return is_link($path); },
        'readLink' => $dependencies['readLink'] ?? static function (string $path): string { $target = readlink($path); return $target === false ? '' : (string) $target; },
        'filePerms' => $dependencies['filePerms'] ?? static function (string $path) { return @fileperms($path); },
        'codename' => getDistroCodename(),
        'sourcesPath' => $sourcesPath,
        'probeSpecs' => pmssStatusProbeSpecs($sourcesPath),
    ];
}
function pmssComponentStatusChecks(array $dependencies = []): array
{
    $context = isset($dependencies['probeSpecs']) ? $dependencies : pmssStatusContextResolve($dependencies);
    $runCommand = $context['runCommand']; $pathExists = $context['pathExists']; $readFile = $context['readFile']; $isExecutable = $context['isExecutable'];
    $codename = (string) $context['codename']; $sourcesPath = (string) $context['sourcesPath'];
    $results[] = $codename === '' ? pmssStatus('os.codename', 'WARN', 'VERSION_CODENAME missing') : pmssStatus('os.codename', 'OK', $codename);
    $results[] = is_file($sourcesPath)
        ? pmssStatus('apt.sources', ($matches = $codename === '' || stripos($readFile($sourcesPath), $codename) !== false) ? 'OK' : 'WARN', $matches ? 'contains '.$codename : 'codename mismatch')
        : pmssStatus('apt.sources', 'WARN', 'missing sources.list');

    return array_merge($results, pmssStatusCollectProbeChecks(
        $context['probeSpecs'],
        static function (string $binary, array $binarySpec) use ($runCommand, $isExecutable): ?array {
            return pmssStatusBinaryProbeCheck($binary, $binarySpec, $runCommand, $isExecutable, true);
        },
        static function (array $pathSpec) use ($pathExists): ?array {
            if (!isset($pathSpec['componentName'])) return null;
            $path = (string) $pathSpec['path'];
            return pmssStatus((string) $pathSpec['componentName'], ($exists = $pathExists($path)) ? 'OK' : 'WARN', $exists ? $path : 'missing');
        }
    ));
}

/**
 * Collect the richer system-test probe used by scripts/util/systemTest.php.
 *
 * Dependencies may be overridden in tests to keep the characterization suite
 * hermetic and independent from host state.
 */
function pmssSystemStatusChecks(array $dependencies = []): array
{
    $context = pmssStatusContextResolve($dependencies);
    $runCommand = $context['runCommand']; $pathExists = $context['pathExists'];
    $isFile = $context['isFile']; $isDir = $context['isDir']; $isExecutable = $context['isExecutable'];
    $isLink = $context['isLink']; $readLink = $context['readLink']; $readFile = $context['readFile']; $filePerms = $context['filePerms'];
    $checks = [];
    $codename = (string) $context['codename'];
    $sourcesPath = (string) $context['sourcesPath'];
    $checks[] = $codename === ''
        ? pmssStatus('OS codename', 'WARN', 'VERSION_CODENAME missing')
        : pmssStatus('OS codename', 'OK', $codename);
    $checks = array_merge($checks, pmssStatusCollectProbeChecks(
        $context['probeSpecs'],
        static function (string $binary, array $binarySpec) use ($runCommand, $isExecutable): ?array {
            return pmssStatusBinaryProbeCheck($binary, $binarySpec, $runCommand, $isExecutable);
        },
        static function (array $pathSpec) use ($pathExists): array {
            $path = (string) $pathSpec['path'];
            $exists = $pathExists($path);
            return pmssStatus((string) $pathSpec['systemLabel'], $exists ? 'OK' : 'WARN', $exists ? $path : $path.' missing');
        }
    ));

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
    } elseif (!pmssHostnameIsValid($hostname)) {
        $checks[] = pmssStatus('OpenVPN client artifacts', 'WARN', 'hostname invalid');
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

    return array_merge($checks, array_map(
        static function (array $entry): array {
            return pmssStatus('Component: '.(string) $entry['name'], (string) $entry['status'], (string) ($entry['detail'] ?? ''));
        },
        pmssComponentStatusChecks($context)
    ));
}
