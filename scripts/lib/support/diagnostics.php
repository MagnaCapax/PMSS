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

require_once dirname(__DIR__).'/runtime.php';
require_once __DIR__.'/stream.php';
require_once __DIR__.'/../lighttpd/userFileWrite.php';
require_once __DIR__.'/../user/billingIds.php';

/**
 * Resolve the current caller identity from trusted process state.
 *
 * @return array<string,string>
 */
function pmssSupportIdentityRead(): array
{
    $isSafeUsername = static function (string $username): bool {
        $username = trim($username);
        if ($username === '' || strpos($username, "\0") !== false) {
            return false;
        }
        if (strpos($username, '/') !== false || strpos($username, '\\') !== false || strpos($username, '..') !== false) {
            return false;
        }
        return preg_match('/[[:space:][:cntrl:]]/', $username) !== 1;
    };

    $homeRoot = rtrim(pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home'), '/');
    $homeRoot = ($homeRoot !== '' && $homeRoot[0] === '/' && strpos($homeRoot, "\0") === false) ? $homeRoot : '';
    $envUser = getenv('USER');
    $envUser = is_string($envUser) ? trim($envUser) : '';
    $envHome = getenv('HOME');
    $envHome = is_string($envHome) ? rtrim($envHome, '/') : '';
    $username = '';

    if ($homeRoot !== '' && $isSafeUsername($envUser)) {
        $expectedHome = $homeRoot.'/'.$envUser;
        if ($expectedHome !== '' && $envHome !== '' && $envHome === $expectedHome) {
            $username = $envUser;
        }
    }

    if ($username === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $entry = @posix_getpwuid(posix_geteuid());
        $name = is_array($entry) && !empty($entry['name']) ? (string) $entry['name'] : '';
        if ($isSafeUsername($name)) {
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
 * Build the read-only diagnostics snapshot body.
 *
 * @return array<string,mixed>
 */
function pmssSupportDiagnosticsBuild(string $message, ?callable $runner = null): array
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

    $message = pmssSupportMessageNormalize($message);
    $identity = pmssSupportIdentityRead();
    $username = (string) $identity['username'];
    $home = (string) $identity['home'];
    $hostname = (string) (gethostname() ?: 'unknown-host');
    $billingServiceId = pmssUserBillingServiceIdRead($home);
    $billingClientId = pmssUserBillingClientIdRead($home);
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
        $result = $runner($command);
        $rc = (int) ($result['rc'] ?? 1);
        $output = trim((string) ($result['output'] ?? ''));
        $sections[] = sprintf("## %s\n%s", $label, ($output === '' ? '[no output]' : $output)."\n[exit status: {$rc}]");
    }

    $body = implode("\n\n", [
        '# PMSS Support Request',
        'submitted_at='.gmdate('c'),
        'username='.$username,
        'billing_service_id='.$billingServiceId,
        'billing_client_id='.$billingClientId,
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
        'billingServiceId' => $billingServiceId,
        'billingClientId' => $billingClientId,
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
    if (!pmssDirEnsureExists($snapshotDir, 0700)) {
        throw new RuntimeException('Unable to create support snapshot directory.');
    }
    if (!is_dir($snapshotDir) || !pmssPathTargetIsSafe($snapshotDir, true)) {
        throw new RuntimeException('Support snapshot directory is unsafe.');
    }
    @chmod($snapshotDir, 0700);
    clearstatcache(true, $snapshotDir);
    $snapshotDirMode = @fileperms($snapshotDir);
    if (is_int($snapshotDirMode) && (($snapshotDirMode & 0777) !== 0700)) {
        throw new RuntimeException('Support snapshot directory permission check failed.');
    }

    $path = sprintf('%s/request-%s-%d.txt', $snapshotDir, gmdate('Ymd-His'), getmypid());
    $previousUmask = umask(0077);
    $handle = @fopen($path, 'x');
    umask($previousUmask);
    if ($handle === false) {
        throw new RuntimeException('Unable to create support snapshot file.');
    }

    $deletePath = true;
    try {
        pmssSupportStreamWriteAll($handle, (string) ($diagnostics['body'] ?? ''), 'support snapshot file');
        if (@fflush($handle) !== true) {
            throw new RuntimeException('Unable to flush support snapshot file.');
        }
        $deletePath = false;
    } finally {
        @fclose($handle);
        if ($deletePath) {
            @unlink($path);
        }
    }

    if (!is_file($path) || is_link($path) || (function_exists('posix_geteuid') && @fileowner($path) !== posix_geteuid())) {
        @unlink($path);
        throw new RuntimeException('Support snapshot file ownership check failed.');
    }

    @chmod($path, 0600);
    clearstatcache(true, $path);
    $snapshotFileMode = @fileperms($path);
    if (is_int($snapshotFileMode) && (($snapshotFileMode & 0777) !== 0600)) {
        @unlink($path);
        throw new RuntimeException('Support snapshot file permission check failed.');
    }

    return $path;
}
