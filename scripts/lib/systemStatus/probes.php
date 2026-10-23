<?php
/** Status probe catalog, bounded command lookup, and injectable host context.
 * @license GPL-3.0-only
 * @author PMSS Team
 */
declare(strict_types=1);

require_once dirname(__DIR__).'/runtime.php';
require_once dirname(__DIR__).'/update/osRelease.php';

// Version probes only need to print a line. Anything slower is wedged, not slow.
const PMSS_STATUS_PROBE_TIMEOUT_SECONDS = 15;

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
    if (!pmssCommandPathCandidateIsSafe($path)) {
        return '';
    }

    return $isExecutable !== null && !$isExecutable($path) ? '' : $path;
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
            ['systemLabel' => 'Seedbox localnet', 'componentName' => 'config.seedbox.localnet', 'path' => '/etc/seedbox/config/localnet'], ['systemLabel' => 'Nginx directory', 'componentName' => 'config.nginx', 'path' => '/etc/nginx'],
        ],
    ];
}

/**
 * Evaluate the shared catalog once and render its system and component views.
 * Component-only callers skip version commands while systemTest reuses the
 * same path observations for its trailing component projection.
 *
 * @return array{system:array<int,array<string,string>>,component:array<int,array<string,string>>}
 */
function pmssStatusProbeViews(array $probeSpecs, callable $runCommand, callable $isExecutable, callable $pathExists, bool $includeSystem): array
{
    $views = ['system' => [], 'component' => []];
    // Binary and path probes share the same ordered report projection.
    $append = static function (array $spec, string $label, bool $present, string $systemDetail, string $componentDetail) use (&$views, $includeSystem): void {
        $status = $present ? 'OK' : 'WARN';
        if ($includeSystem) $views['system'][] = pmssStatus($label, $status, $systemDetail);
        if (isset($spec['componentName'])) $views['component'][] = pmssStatus((string) $spec['componentName'], $status, $componentDetail);
    };
    foreach (is_array($probeSpecs['binaries'] ?? null) ? $probeSpecs['binaries'] : [] as $binary => $spec) {
        $spec = is_array($spec) ? $spec : [];
        if (!$includeSystem && !isset($spec['componentName'])) continue;
        $binary = (string) $binary;
        $path = pmssStatusBinaryPathResolve($binary, $runCommand, $isExecutable);
        $detail = 'Not found in PATH';
        if ($includeSystem && $path !== '') {
            $infoCommand = is_string($spec['infoCommand'] ?? null) ? trim($spec['infoCommand']) : '';
            $info = $infoCommand === '' ? '' : trim((string) $runCommand($infoCommand));
            $detail = $info !== '' ? $info : 'present';
        }
        $append($spec, 'Binary: '.$binary, $path !== '', $detail, $path);
    }

    foreach (is_array($probeSpecs['paths'] ?? null) ? $probeSpecs['paths'] : [] as $spec) {
        if (!is_array($spec) || (!$includeSystem && !isset($spec['componentName']))) continue;
        $path = (string) ($spec['path'] ?? '');
        $exists = $pathExists($path);
        $append($spec, $includeSystem ? (string) ($spec['systemLabel'] ?? $path) : '', (bool) $exists, $exists ? $path : $path.' missing', $exists ? $path : 'missing');
    }

    return $views;
}

function pmssStatusContextResolve(array $dependencies = []): array
{
    $sourcesPath = pmssResolvePathFromEnv('PMSS_APT_SOURCES_PATH', '/etc/apt/sources.list');
    return [
        // Bounded capture, not shell_exec: the binary probes below run installed third-party
        // binaries as root to read a version string. shell_exec has no deadline, so a
        // daemon-capable binary that ignores its version flag would wedge systemTest and then
        // outlive it as an orphan -- the ADR 0034 / ADR 0035 failure mode. pmssCommandCapture
        // bounds the wait AND runs the child in its own process group so the timeout reaches it.
        'runCommand' => $dependencies['runCommand'] ?? static function (string $command): string {
            $result = pmssCommandCapture($command, PMSS_STATUS_PROBE_TIMEOUT_SECONDS);
            return trim((string) ($result['stdout'] ?? ''));
        },
        'pathExists' => $dependencies['pathExists'] ?? static function (string $path): bool { return is_dir($path) || is_file($path); },
        'readFile' => $dependencies['readFile'] ?? static function (string $path): string { return (string) @file_get_contents($path); },
        'isFile' => $dependencies['isFile'] ?? 'is_file',
        'isDir' => $dependencies['isDir'] ?? 'is_dir',
        'isExecutable' => $dependencies['isExecutable'] ?? 'is_executable',
        'isLink' => $dependencies['isLink'] ?? 'is_link',
        'readLink' => $dependencies['readLink'] ?? static function (string $path): string { return (string) readlink($path); },
        'filePerms' => $dependencies['filePerms'] ?? static function (string $path) { return @fileperms($path); },
        'codename' => getDistroCodename(),
        'sourcesPath' => $sourcesPath,
        'probeSpecs' => pmssStatusProbeSpecs($sourcesPath),
    ];
}

