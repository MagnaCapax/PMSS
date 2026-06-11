<?php
/**
 * LinuxServer.io container installer helpers for tenant-facing wrappers.
 *
 * Keeps the user-home command surface small while centralizing the actual
 * Docker command construction and dry-run safety checks in first-party PHP.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';

/**
 * Build the command usage text for the current entrypoint name.
 */
function pmssDockerInstallLsioUsage(string $scriptName): string
{
    return sprintf(
        "Usage: %s APP [HOST_PORT] [--dry-run]\n\nSupported apps: %s\n",
        $scriptName,
        implode(' ', array_keys(pmssDockerInstallLsioAppCatalog()))
    );
}

/**
 * Generate a stable database identifier from user-derived input.
 */
function pmssDockerInstallLsioDatabaseIdentifier(string $seed, string $prefix): string
{
    $value = strtolower($seed);
    $value = (string) preg_replace('/[^a-z0-9_]+/', '_', $value);
    $value = trim($value, '_');
    if ($value === '') {
        $value = 'user';
    }

    return substr($prefix.$value, 0, 24);
}

/**
 * Generate a random secret for service-local credentials.
 */
function pmssDockerInstallLsioRandomSecret(): string
{
    return bin2hex(random_bytes(18));
}

/**
 * Read the host timezone for container defaults.
 */
function pmssDockerInstallLsioTimezone(): string
{
    $timezone = pmssReadRegularFileTrimmed('/etc/timezone');
    return $timezone !== null && $timezone !== '' ? $timezone : 'UTC';
}

/**
 * Render a command array as a shell-like string for dry-run output.
 *
 * @param array<int,string> $command
 */
function pmssDockerInstallLsioDisplayCommand(array $command): string
{
    return implode(' ', array_map(static function (string $value): string {
        return preg_match('/^[A-Za-z0-9_@:.,+\/=-]+$/', $value) === 1 ? $value : escapeshellarg($value);
    }, $command));
}

/**
 * Execute a command while streaming output to the caller.
 *
 * @param array<int,string> $command
 */
function pmssDockerInstallLsioPassthru(array $command): int
{
    $rc = 0;
    passthru(pmssCommandArgvShellQuote($command), $rc);
    return (int) $rc;
}

/**
 * Create a directory tree when it does not already exist.
 *
 * @param array<int,string> $paths
 */
function pmssDockerInstallLsioEnsureDirectories(array $paths): bool
{
    foreach ($paths as $path) {
        if ($path === '') {
            continue;
        }
        if (is_dir($path)) {
            continue;
        }
        if (file_exists($path)) {
            fwrite(STDERR, "Path already exists and is not a directory: {$path}\n");
            return false;
        }
        if (!pmssDirEnsureExists($path, 0755)) {
            fwrite(STDERR, "Failed to create directory: {$path}\n");
            return false;
        }
    }

    return true;
}

/**
 * Persist generated MariaDB credentials with restrictive permissions.
 */
function pmssDockerInstallLsioWriteCredentialFile(string $credentialFile, string $dbName, string $dbUser): bool
{
    $payload = implode("\n", [
        'MYSQL_ROOT_PASSWORD='.pmssDockerInstallLsioRandomSecret(),
        'MYSQL_DATABASE='.$dbName,
        'MYSQL_USER='.$dbUser,
        'MYSQL_PASSWORD='.pmssDockerInstallLsioRandomSecret(),
        '',
    ]);

    if (@file_put_contents($credentialFile, $payload) === false) {
        fwrite(STDERR, "Failed to write credential file: {$credentialFile}\n");
        return false;
    }

    @chmod($credentialFile, 0600);
    return true;
}

/**
 * Validate an optional CLI host port override.
 */
function pmssDockerInstallLsioHostPort(?string $value, string $defaultPort): ?string
{
    if ($value === null || $value === '') {
        return $defaultPort;
    }

    $port = pmssNetworkPortParseDigits($value);
    return $port === null ? null : (string) $port;
}

/**
 * Catalog the supported LSIO apps in the public usage order.
 *
 * @return array<string,array<string,mixed>>
 */
function pmssDockerInstallLsioAppCatalog(): array
{
    return [
        'jellyfin' => ['port' => '8096', 'mkdir' => ['media'], 'volumes' => ['config' => '/config', 'media' => '/data']],
        'qbittorrent' => ['port' => '8080', 'mkdir' => ['downloads'], 'extraArgs' => ['-e', 'WEBUI_PORT=8080'], 'volumes' => ['config' => '/config', 'downloads' => '/downloads']],
        'radarr' => ['port' => '7878', 'mkdir' => ['movies', 'downloads'], 'volumes' => ['config' => '/config', 'movies' => '/movies', 'downloads' => '/downloads']],
        'sonarr' => ['port' => '8989', 'mkdir' => ['tv', 'downloads'], 'volumes' => ['config' => '/config', 'tv' => '/tv', 'downloads' => '/downloads']],
        'prowlarr' => ['port' => '9696', 'volumes' => ['config' => '/config']],
        'mariadb' => ['port' => '3306', 'bindHost' => '127.0.0.1', 'credentials' => true, 'volumes' => ['config' => '/config']],
        'phpmyadmin' => ['port' => '8082', 'containerPort' => '80', 'bindHost' => '127.0.0.1', 'extraArgs' => ['-e', 'PMA_HOST=mariadb', '-e', 'PMA_PORT=3306'], 'volumes' => ['config' => '/config']],
    ];
}

/**
 * Build the app-specific install specification.
 *
 * @return array<string,mixed>|null
 */
function pmssDockerInstallLsioAppSpec(string $app, string $homeDir, bool $dryRun)
{
    $catalog = pmssDockerInstallLsioAppCatalog();
    if (!isset($catalog[$app])) {
        return null;
    }

    $appSpec = $catalog[$app];
    $paths = [
        'config' => $homeDir.'/docker/'.$app.'/config',
        'downloads' => $homeDir.'/downloads',
        'movies' => $homeDir.'/movies',
        'tv' => $homeDir.'/tv',
        'media' => $homeDir.'/media',
    ];

    $spec = [
        'image' => 'lscr.io/linuxserver/'.$app.':latest',
        'mkdirPaths' => [$paths['config']],
        'bindHost' => (string) ($appSpec['bindHost'] ?? ''),
        'containerPort' => (string) ($appSpec['containerPort'] ?? $appSpec['port']),
        'defaultPort' => (string) $appSpec['port'],
        'extraArgs' => $appSpec['extraArgs'] ?? [],
        'volumeArgs' => [],
        'credentialFile' => '',
    ];

    foreach ($appSpec['mkdir'] ?? [] as $pathKey) {
        if (isset($paths[$pathKey])) {
            $spec['mkdirPaths'][] = $paths[$pathKey];
        }
    }

    foreach ($appSpec['volumes'] ?? [] as $pathKey => $containerPath) {
        if (!isset($paths[$pathKey]) || $containerPath === '') {
            continue;
        }
        $spec['volumeArgs'][] = '-v';
        $spec['volumeArgs'][] = $paths[$pathKey].':'.$containerPath;
    }

    if (!empty($appSpec['credentials'])) {
        $credentialFile = $homeDir.'/docker/'.$app.'/pmss-credentials.env';
        $dbUser = pmssDockerInstallLsioDatabaseIdentifier(basename(rtrim($homeDir, '/')), 'db_');
        $dbName = pmssDockerInstallLsioDatabaseIdentifier($dbUser.'_app', '');
        $spec['credentialFile'] = $credentialFile;
        $spec['dbName'] = $dbName;
        $spec['dbUser'] = $dbUser;
        $spec['extraArgs'] = $dryRun
            ? ['-e', 'MYSQL_ROOT_PASSWORD=<generated-at-install>', '-e', 'MYSQL_DATABASE='.$dbName, '-e', 'MYSQL_USER='.$dbUser, '-e', 'MYSQL_PASSWORD=<generated-at-install>']
            : ['--env-file', $credentialFile];
    }

    return $spec;
}

/**
 * Build the final docker run command for the selected app.
 *
 * @param array<string,mixed> $spec
 * @return array<int,string>
 */
function pmssDockerInstallLsioDockerRunCommand(string $app, string $hostPort, string $timezone, array $spec): array
{
    $command = ['docker', 'run', '-d', '--name', $app, '-e', 'PUID=0', '-e', 'PGID=0', '-e', 'TZ='.$timezone, '--network', 'pmss-media'];

    $command[] = '-p';
    $command[] = (string) $spec['bindHost'] !== ''
        ? $spec['bindHost'].':'.$hostPort.':'.$spec['containerPort']
        : $hostPort.':'.$spec['containerPort'];

    foreach (['extraArgs', 'volumeArgs'] as $key) {
        foreach ($spec[$key] as $argument) {
            $command[] = $argument;
        }
    }

    $command[] = '--restart';
    $command[] = 'unless-stopped';
    $command[] = $spec['image'];
    return $command;
}

/**
 * Main CLI handler for the tenant-facing LSIO installer.
 */
function pmssDockerInstallLsioMain(array $argv): int
{
    $args = array_values($argv);
    $scriptName = basename((string) ($args[0] ?? 'docker-install-lsio'));
    array_shift($args);

    $dryRun = false;
    $positionals = [];
    foreach ($args as $arg) {
        if ($arg === '--dry-run') {
            $dryRun = true;
            continue;
        }
        $positionals[] = $arg;
    }

    if (count($positionals) < 1 || count($positionals) > 2) {
        fwrite(STDERR, pmssDockerInstallLsioUsage($scriptName));
        return 1;
    }

    $app = (string) $positionals[0];
    $homeDir = (string) getenv('HOME');
    if ($homeDir === '') {
        fwrite(STDERR, "HOME is not set.\n");
        return 1;
    }

    $spec = pmssDockerInstallLsioAppSpec($app, $homeDir, $dryRun);
    if ($spec === null) {
        fwrite(STDERR, pmssDockerInstallLsioUsage($scriptName));
        return 1;
    }

    $hostPort = pmssDockerInstallLsioHostPort(isset($positionals[1]) ? (string) $positionals[1] : null, (string) $spec['defaultPort']);
    if ($hostPort === null) {
        fwrite(STDERR, "Invalid host port; expected an integer between 1 and 65535.\n");
        return 1;
    }

    $dockerRun = pmssDockerInstallLsioDockerRunCommand($app, $hostPort, pmssDockerInstallLsioTimezone(), $spec);

    if ($dryRun) {
        echo '[dry-run] docker network inspect pmss-media || docker network create pmss-media' . PHP_EOL;
        echo '[dry-run] ' . pmssDockerInstallLsioDisplayCommand($dockerRun) . PHP_EOL;
        return 0;
    }

    if (pmssCommandPath('docker') === '') {
        fwrite(STDERR, "docker command not found in PATH\n");
        return 1;
    }

    if (pmssCommandCapture(pmssCommandArgvShellQuote(['docker', 'info']))['rc'] !== 0) {
        fwrite(STDERR, "Docker daemon unavailable; wait for the PMSS rootless Docker watchdog and retry.\n");
        return 1;
    }

    if (pmssCommandCapture(pmssCommandArgvShellQuote(['docker', 'container', 'inspect', $app]))['rc'] === 0) {
        fwrite(STDERR, "Container {$app} already exists; remove it manually if you want to recreate it.\n");
        return 1;
    }

    if (pmssCommandCapture(pmssCommandArgvShellQuote(['docker', 'network', 'inspect', 'pmss-media']))['rc'] !== 0) {
        if (pmssDockerInstallLsioPassthru(['docker', 'network', 'create', 'pmss-media']) !== 0) {
            return 1;
        }
    }

    if (!pmssDockerInstallLsioEnsureDirectories($spec['mkdirPaths'])) {
        return 1;
    }

    if ($app === 'mariadb'
        && (string) $spec['credentialFile'] !== ''
        && !is_file((string) $spec['credentialFile'])
        && !pmssDockerInstallLsioWriteCredentialFile((string) $spec['credentialFile'], (string) $spec['dbName'], (string) $spec['dbUser'])) {
        return 1;
    }

    return pmssDockerInstallLsioPassthru($dockerRun);
}
