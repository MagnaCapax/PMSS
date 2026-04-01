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
 * Return the supported LSIO app identifiers in help-text order.
 *
 * @return array<int,string>
 */
function pmssDockerInstallLsioSupportedApps(): array
{
    return ['jellyfin', 'qbittorrent', 'radarr', 'sonarr', 'prowlarr', 'mariadb', 'phpmyadmin'];
}

/**
 * Build the command usage text for the current entrypoint name.
 */
function pmssDockerInstallLsioUsage(string $scriptName): string
{
    return sprintf(
        "Usage: %s APP [HOST_PORT] [--dry-run]\n\nSupported apps: %s\n",
        $scriptName,
        implode(' ', pmssDockerInstallLsioSupportedApps())
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
    $timezone = @file_get_contents('/etc/timezone');
    $timezone = is_string($timezone) ? trim($timezone) : '';
    return $timezone !== '' ? $timezone : 'UTC';
}

/**
 * Quote a shell word for display while keeping simple arguments readable.
 */
function pmssDockerInstallLsioDisplayWord(string $value): string
{
    return preg_match('/^[A-Za-z0-9_@:.,+\/=-]+$/', $value) === 1 ? $value : escapeshellarg($value);
}

/**
 * Render a command array as a shell-like string for dry-run output.
 *
 * @param array<int,string> $command
 */
function pmssDockerInstallLsioDisplayCommand(array $command): string
{
    return implode(' ', array_map('pmssDockerInstallLsioDisplayWord', $command));
}

/**
 * Render a command array for actual shell execution.
 *
 * @param array<int,string> $command
 */
function pmssDockerInstallLsioShellCommand(array $command): string
{
    return implode(' ', array_map('escapeshellarg', $command));
}

/**
 * Execute a command and capture its exit status and output.
 *
 * @param array<int,string> $command
 * @return array{rc:int,stdout:string,stderr:string}
 */
function pmssDockerInstallLsioCommandCapture(array $command): array
{
    return pmssCommandCapture(pmssDockerInstallLsioShellCommand($command));
}

/**
 * Execute a command while streaming output to the caller.
 *
 * @param array<int,string> $command
 */
function pmssDockerInstallLsioPassthru(array $command): int
{
    $rc = 0;
    passthru(pmssDockerInstallLsioShellCommand($command), $rc);
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
        if (!@mkdir($path, 0755, true) && !is_dir($path)) {
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
 * Build the app-specific install specification.
 *
 * @return array<string,mixed>|null
 */
function pmssDockerInstallLsioAppSpec(string $app, string $homeDir, bool $dryRun)
{
    $configDir = $homeDir.'/docker/'.$app.'/config';
    $downloadsDir = $homeDir.'/downloads';
    $moviesDir = $homeDir.'/movies';
    $tvDir = $homeDir.'/tv';
    $mediaDir = $homeDir.'/media';
    $credentialFile = '';
    $dbName = '';
    $dbUser = '';

    $spec = [
        'image' => 'lscr.io/linuxserver/'.$app.':latest',
        'configDir' => $configDir,
        'mkdirPaths' => [$configDir],
        'bindHost' => '',
        'containerPort' => '',
        'defaultPort' => '',
        'extraArgs' => [],
        'volumeArgs' => [],
        'credentialFile' => '',
    ];

    switch ($app) {
        case 'jellyfin':
            $spec['defaultPort'] = '8096';
            $spec['containerPort'] = '8096';
            $spec['mkdirPaths'][] = $mediaDir;
            $spec['volumeArgs'] = ['-v', $configDir.':/config', '-v', $mediaDir.':/data'];
            return $spec;

        case 'qbittorrent':
            $spec['defaultPort'] = '8080';
            $spec['containerPort'] = '8080';
            $spec['mkdirPaths'][] = $downloadsDir;
            $spec['extraArgs'] = ['-e', 'WEBUI_PORT=8080'];
            $spec['volumeArgs'] = ['-v', $configDir.':/config', '-v', $downloadsDir.':/downloads'];
            return $spec;

        case 'radarr':
            $spec['defaultPort'] = '7878';
            $spec['containerPort'] = '7878';
            $spec['mkdirPaths'][] = $moviesDir;
            $spec['mkdirPaths'][] = $downloadsDir;
            $spec['volumeArgs'] = ['-v', $configDir.':/config', '-v', $moviesDir.':/movies', '-v', $downloadsDir.':/downloads'];
            return $spec;

        case 'sonarr':
            $spec['defaultPort'] = '8989';
            $spec['containerPort'] = '8989';
            $spec['mkdirPaths'][] = $tvDir;
            $spec['mkdirPaths'][] = $downloadsDir;
            $spec['volumeArgs'] = ['-v', $configDir.':/config', '-v', $tvDir.':/tv', '-v', $downloadsDir.':/downloads'];
            return $spec;

        case 'prowlarr':
            $spec['defaultPort'] = '9696';
            $spec['containerPort'] = '9696';
            $spec['volumeArgs'] = ['-v', $configDir.':/config'];
            return $spec;

        case 'mariadb':
            $credentialFile = $homeDir.'/docker/'.$app.'/pmss-credentials.env';
            $dbUser = pmssDockerInstallLsioDatabaseIdentifier(basename(rtrim($homeDir, '/')), 'db_');
            $dbName = pmssDockerInstallLsioDatabaseIdentifier($dbUser.'_app', '');
            $spec['defaultPort'] = '3306';
            $spec['containerPort'] = '3306';
            $spec['bindHost'] = '127.0.0.1';
            $spec['credentialFile'] = $credentialFile;
            $spec['dbName'] = $dbName;
            $spec['dbUser'] = $dbUser;
            $spec['extraArgs'] = $dryRun
                ? ['-e', 'MYSQL_ROOT_PASSWORD=<generated-at-install>', '-e', 'MYSQL_DATABASE='.$dbName, '-e', 'MYSQL_USER='.$dbUser, '-e', 'MYSQL_PASSWORD=<generated-at-install>']
                : ['--env-file', $credentialFile];
            $spec['volumeArgs'] = ['-v', $configDir.':/config'];
            return $spec;

        case 'phpmyadmin':
            $spec['defaultPort'] = '8082';
            $spec['containerPort'] = '80';
            $spec['bindHost'] = '127.0.0.1';
            $spec['extraArgs'] = ['-e', 'PMA_HOST=mariadb', '-e', 'PMA_PORT=3306'];
            $spec['volumeArgs'] = ['-v', $configDir.':/config'];
            return $spec;
    }

    return null;
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

    if ((string) $spec['bindHost'] !== '') {
        $command[] = '-p';
        $command[] = $spec['bindHost'].':'.$hostPort.':'.$spec['containerPort'];
    } else {
        $command[] = '-p';
        $command[] = $hostPort.':'.$spec['containerPort'];
    }

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

    $hostPort = isset($positionals[1]) && $positionals[1] !== '' ? (string) $positionals[1] : (string) $spec['defaultPort'];
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

    if (pmssDockerInstallLsioCommandCapture(['docker', 'info'])['rc'] !== 0) {
        fwrite(STDERR, "Docker daemon unavailable; wait for the PMSS rootless Docker watchdog and retry.\n");
        return 1;
    }

    if (pmssDockerInstallLsioCommandCapture(['docker', 'container', 'inspect', $app])['rc'] === 0) {
        fwrite(STDERR, "Container {$app} already exists; remove it manually if you want to recreate it.\n");
        return 1;
    }

    if (pmssDockerInstallLsioCommandCapture(['docker', 'network', 'inspect', 'pmss-media'])['rc'] !== 0) {
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
