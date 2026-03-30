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

/**
 * Determine the current username from process state.
 */
function pmssSupportCurrentUsernameRead(): string
{
    $user = getenv('USER');
    $user = is_string($user) ? trim($user) : '';
    if ($user !== '') {
        $home = getenv('HOME');
        $home = is_string($home) ? rtrim($home, '/') : '';
        $expectedHome = rtrim(pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home'), '/').'/'.$user;
        if ($home !== '' && $home === $expectedHome) {
            return $user;
        }
    }

    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $entry = @posix_getpwuid(posix_geteuid());
        if (is_array($entry) && !empty($entry['name'])) {
            return (string) $entry['name'];
        }
    }

    return $user;
}

/**
 * Resolve the current home directory without trusting arbitrary input.
 */
function pmssSupportCurrentHomeRead(string $username): string
{
    $expectedHome = '';
    if ($username !== '') {
        $expectedHome = rtrim(pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home'), '/').'/'.$username;
    }

    $home = getenv('HOME');
    $home = is_string($home) ? rtrim($home, '/') : '';
    if ($home !== '' && ($expectedHome === '' || $home === $expectedHome)) {
        return $home;
    }

    if ($username !== '' && function_exists('posix_getpwnam')) {
        $entry = @posix_getpwnam($username);
        if (is_array($entry) && !empty($entry['dir'])) {
            return rtrim((string) $entry['dir'], '/');
        }
    }

    return $expectedHome;
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
    if (!is_file($path) || is_link($path)) {
        return 0;
    }

    $billingId = (int) trim((string) @file_get_contents($path));
    return $billingId > 0 ? $billingId : 0;
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
    $username = pmssSupportCurrentUsernameRead();
    $home = pmssSupportCurrentHomeRead($username);
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
    if ($home === '') {
        throw new RuntimeException('Unable to determine support snapshot home directory.');
    }

    $snapshotDir = $home.'/'.trim((string) $config['snapshotDirectory'], '/');
    if (is_link($snapshotDir)) {
        throw new RuntimeException('Support snapshot directory is unsafe.');
    }
    if (!is_dir($snapshotDir) && !@mkdir($snapshotDir, 0700, true) && !is_dir($snapshotDir)) {
        throw new RuntimeException('Unable to create support snapshot directory.');
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
