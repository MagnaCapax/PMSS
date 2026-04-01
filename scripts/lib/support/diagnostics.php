<?php
/**
 * Support command diagnostics helpers.
 *
 * Builds the read-only tenant snapshot saved locally and attached to support
 * requests. The command list is fixed in code so user input never crosses the
 * shell boundary.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/config.php';
require_once __DIR__.'/../lighttpd/userFileWrite.php';

/**
 * Accept only simple local account names from process environment.
 */
function pmssSupportUsernameIsSafe(string $username): bool
{
    $username = trim($username);
    if ($username === '' || strpos($username, "\0") !== false) {
        return false;
    }
    if (strpos($username, '/') !== false || strpos($username, '\\') !== false || strpos($username, '..') !== false) {
        return false;
    }
    if (preg_match('/[[:space:][:cntrl:]]/', $username) === 1) {
        return false;
    }

    return true;
}

/**
 * Resolve the current caller identity from trusted process state.
 *
 * @return array<string,string>
 */
function pmssSupportIdentityRead(): array
{
    $homeRoot = rtrim(pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home'), '/');
    $homeRoot = ($homeRoot !== '' && $homeRoot[0] === '/' && strpos($homeRoot, "\0") === false) ? $homeRoot : '';
    $envUser = getenv('USER');
    $envUser = is_string($envUser) ? trim($envUser) : '';
    $envHome = getenv('HOME');
    $envHome = is_string($envHome) ? rtrim($envHome, '/') : '';
    $username = '';

    if ($homeRoot !== '' && pmssSupportUsernameIsSafe($envUser)) {
        $expectedHome = $homeRoot.'/'.$envUser;
        if ($expectedHome !== '' && $envHome !== '' && $envHome === $expectedHome) {
            $username = $envUser;
        }
    }

    if ($username === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $entry = @posix_getpwuid(posix_geteuid());
        $name = is_array($entry) && !empty($entry['name']) ? (string) $entry['name'] : '';
        if (pmssSupportUsernameIsSafe($name)) {
            $username = $name;
        }
    }

    $expectedHome = ($username !== '' && $homeRoot !== '') ? $homeRoot.'/'.$username : '';
    if ($expectedHome !== '' && $envHome !== '' && $envHome === $expectedHome && pmssPathTargetIsSafe($envHome, true)) {
        return ['username' => $username, 'home' => $envHome];
    }

    if ($username !== '' && function_exists('posix_getpwnam')) {
        $entry = @posix_getpwnam($username);
        $dir = is_array($entry) && !empty($entry['dir']) ? rtrim((string) $entry['dir'], '/') : '';
        if ($dir !== '' && pmssPathTargetIsSafe($dir, true)) {
            return ['username' => $username, 'home' => $dir];
        }
    }

    return ['username' => $username, 'home' => $expectedHome];
}

/**
 * Validate and normalize the user-supplied support message.
 */
function pmssSupportMessageNormalize(string $message): string
{
    $message = trim(str_replace(["\r\n", "\r"], "\n", $message));
    if ($message === '') {
        throw new InvalidArgumentException('Support message is required.');
    }
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $message) === 1) {
        throw new InvalidArgumentException('Support message contains unsupported control characters.');
    }
    if (strlen($message) > 5000) {
        throw new InvalidArgumentException('Support message is too long.');
    }
    return $message;
}

/**
 * Read the billing/service identifier from the user home.
 */
function pmssSupportBillingIdRead(string $home): int
{
    $path = rtrim($home, '/').'/.billingId';
    $value = (int) pmssReadRegularFileTrimmed($path);
    return $value > 0 ? $value : 0;
}

/**
 * Execute a fixed read-only command and return a printable result block.
 *
 * @param array<int,string> $command
 */
function pmssSupportCommandOutputRead(array $command, ?callable $runner = null): string
{
    $runner = $runner ?: function (array $argv): array {
        $parts = [];
        foreach ($argv as $part) {
            $parts[] = escapeshellarg($part);
        }
        $output = [];
        $rc = 0;
        exec(implode(' ', $parts).' 2>&1', $output, $rc);
        return ['rc' => $rc, 'output' => implode("\n", $output)];
    };

    $result = $runner($command);
    $rc = (int) ($result['rc'] ?? 1);
    $output = trim((string) ($result['output'] ?? ''));
    return ($output === '' ? '[no output]' : $output)."\n[exit status: {$rc}]";
}

/**
 * Build the read-only diagnostics snapshot body.
 *
 * @return array<string,mixed>
 */
function pmssSupportDiagnosticsBuild(string $message, ?callable $runner = null): array
{
    $message = pmssSupportMessageNormalize($message);
    $identity = pmssSupportIdentityRead();
    $username = (string) $identity['username'];
    $home = (string) $identity['home'];
    $hostname = (string) (gethostname() ?: 'unknown-host');
    $billingId = pmssSupportBillingIdRead($home);
    $versionPath = pmssResolvePathFromEnv('PMSS_VERSION_FILE', pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config').'/version');
    $pmssVersion = is_file($versionPath) ? trim((string) @file_get_contents($versionPath)) : 'unknown';
    $commands = [
        'uptime' => ['uptime'],
        'disk' => ['df', '-P', '-h', $home !== '' ? $home : '.'],
        'rtorrent' => ['pgrep', '-a', '-u', $username, '-x', 'rtorrent'],
        'deluged' => ['pgrep', '-a', '-u', $username, '-x', 'deluged'],
        'deluge-web' => ['pgrep', '-a', '-u', $username, '-x', 'deluge-web'],
        'qbittorrent-nox' => ['pgrep', '-a', '-u', $username, '-x', 'qbittorrent-nox'],
    ];

    $sections = [];
    foreach ($commands as $label => $command) {
        $sections[] = sprintf("## %s\n%s", $label, pmssSupportCommandOutputRead($command, $runner));
    }

    $body = implode("\n\n", [
        '# PMSS Support Request',
        'submitted_at='.gmdate('c'),
        'username='.$username,
        'billing_id='.$billingId,
        'hostname='.$hostname,
        'pmss_version='.$pmssVersion,
        '',
        '## message',
        $message,
        '',
        implode("\n\n", $sections),
        '',
    ]);

    return [
        'message' => $message,
        'username' => $username,
        'home' => $home,
        'hostname' => $hostname,
        'billingId' => $billingId,
        'pmssVersion' => $pmssVersion,
        'body' => $body,
    ];
}

/**
 * Persist the diagnostics snapshot under the caller's home directory.
 */
function pmssSupportSnapshotWrite(array $diagnostics, array $config): string
{
    $home = rtrim((string) ($diagnostics['home'] ?? ''), '/');
    if ($home === '' || $home[0] !== '/' || !is_dir($home) || !pmssPathTargetIsSafe($home, true)) {
        throw new RuntimeException('Unable to determine support snapshot home directory.');
    }

    $snapshotDir = $home.'/'.trim((string) $config['snapshotDirectory'], '/');
    if (!pmssPathTargetIsSafe($snapshotDir, true)) {
        throw new RuntimeException('Support snapshot directory is unsafe.');
    }
    if (!is_dir($snapshotDir) && !@mkdir($snapshotDir, 0700, true) && !is_dir($snapshotDir)) {
        throw new RuntimeException('Unable to create support snapshot directory.');
    }
    if (!is_dir($snapshotDir) || !pmssPathTargetIsSafe($snapshotDir, true)) {
        throw new RuntimeException('Support snapshot directory is unsafe.');
    }

    $path = sprintf('%s/request-%s-%d.txt', $snapshotDir, gmdate('Ymd-His'), getmypid());
    $previousUmask = umask(0077);
    $handle = @fopen($path, 'x');
    umask($previousUmask);
    if ($handle === false) {
        throw new RuntimeException('Unable to create support snapshot file.');
    }

    try {
        if (@fwrite($handle, (string) ($diagnostics['body'] ?? '')) === false) {
            throw new RuntimeException('Unable to write support snapshot file.');
        }
    } finally {
        @fclose($handle);
    }

    if (!is_file($path) || is_link($path) || (function_exists('posix_geteuid') && @fileowner($path) !== posix_geteuid())) {
        @unlink($path);
        throw new RuntimeException('Support snapshot file ownership check failed.');
    }

    @chmod($path, 0600);
    clearstatcache(true, $path);
    return $path;
}
