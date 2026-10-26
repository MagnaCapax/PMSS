<?php
/**
 * qBittorrent configuration helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/traffic.php';
require_once __DIR__.'/qbittorrentConfig.php';
require_once __DIR__.'/qbittorrentBuild.php';
require_once __DIR__.'/../lighttpd/userFileWrite.php';
require_once __DIR__.'/qbittorrentVersion.php';
require_once __DIR__.'/credentialProcess.php';
require_once __DIR__.'/qbittorrentThrottle.php';

/**
 * Resolve the canonical qBittorrent config path for a user.
 */
function pmssQbittorrentConfigPath(string $username): string
{
    return pmssUserHomeFilePath($username, '.config/qBittorrent/qBittorrent.conf');
}

/**
 * Return the PMSS-managed qBittorrent keys and their enforced values.
 *
 * Keep this list intentionally small: only settings that materially affect
 * shared-host safety or the reverse-proxy contract belong here.
 *
 * @return array<int, array{section:string,key:string,value:string}>
 */
function pmssQbittorrentManagedConfigEntries(string $compatFamily = ''): array
{
    $entries = [
        ['section' => 'BitTorrent', 'key' => 'Session\\AsyncIOThreadsCount', 'value' => '4'],
        ['section' => 'BitTorrent', 'key' => 'Session\\DiskCacheSize', 'value' => '128'],
        ['section' => 'BitTorrent', 'key' => 'Session\\DiskCacheTTL', 'value' => '120'],
        ['section' => 'BitTorrent', 'key' => 'Session\\MaxConnections', 'value' => '300'],
        ['section' => 'BitTorrent', 'key' => 'Session\\MaxConnectionsPerTorrent', 'value' => '75'],
        ['section' => 'BitTorrent', 'key' => 'Session\\Preallocation', 'value' => 'true'],
        ['section' => 'Preferences', 'key' => 'Bittorrent\\MaxConnecs', 'value' => '300'],
        ['section' => 'Preferences', 'key' => 'Bittorrent\\MaxConnecsPerTorrent', 'value' => '75'],
        ['section' => 'Preferences', 'key' => 'Downloads\\DiskWriteCacheSize', 'value' => '128'],
        ['section' => 'Preferences', 'key' => 'Downloads\\DiskWriteCacheTTL', 'value' => '120'],
        ['section' => 'Preferences', 'key' => 'Downloads\\PreAllocation', 'value' => 'true'],
        ['section' => 'Preferences', 'key' => 'WebUI\\CSRFProtection', 'value' => 'true'],
        ['section' => 'Preferences', 'key' => 'WebUI\\ClickjackingProtection', 'value' => 'true'],
        // Keep off until per-user WebUI\ServerDomains contains customer WebUI hostnames and a live test host verifies WebUI load; blind enabling risks fleet-wide 403s.
        ['section' => 'Preferences', 'key' => 'WebUI\\HostHeaderValidation', 'value' => 'false'],
    ];
    $diskIoType = pmssQbittorrentDiskIoType($compatFamily);
    if ($diskIoType !== null) {
        $entries[] = ['section' => 'BitTorrent', 'key' => 'Session\\DiskIOType', 'value' => $diskIoType];
    }
    // Reconcile these with the running daemon only on hosts opted into the rollout.
    if (is_file('/etc/seedbox/config/qbittorrentWebuiEnforce.enabled')) {
        $entries[] = ['section' => 'Preferences', 'key' => 'WebUI\\LocalHostAuth', 'value' => 'true'];
        $entries[] = ['section' => 'Preferences', 'key' => 'WebUI\\AuthSubnetWhitelistEnabled', 'value' => 'false'];
    }
    return $entries;
}

/**
 * Load, transform, and atomically rewrite a user's qBittorrent config.
 */
function pmssQbittorrentConfigMutate(string $username, callable $mutator, ?string $configFile = null): bool
{
    if ($configFile === null) {
        $configFile = pmssQbittorrentConfigPath($username);
    }
    $config = pmssReadRegularFileContents($configFile);
    if ($config === null) {
        return false;
    }

    $updatedConfig = $mutator($config);
    if (!is_string($updatedConfig) || $updatedConfig === $config) {
        return false;
    }

    $mode = @fileperms($configFile);

    return pmssReplaceUserFilePreservingMetadata($configFile, $updatedConfig,
        is_int($mode) ? ($mode & 0777) : 0600);
}

/**
 * Generate the qBittorrent PBKDF2 password hash format.
 */
function pmssQbittorrentPasswordHashGenerate(string $password): string
{
    $salt = random_bytes(16);
    $hash = hash_pbkdf2('sha512', $password, $salt, 100000, 64, true);

    return '@ByteArray(' . base64_encode($salt) . ':' . base64_encode($hash) . ')';
}

/**
 * Refresh the PMSS-managed subset of a user's qBittorrent config.
 */
function pmssQbittorrentApplyManagedConfig(string $username, ?string $configFile = null): bool
{
    return pmssQbittorrentConfigMutate(
        $username,
        static function (string $config) use ($username): ?string {
            $updatedConfig = $config;
            $family = pmssQbittorrentCompatFamily($username);
            if ($family === 'lt1.2') {
                $updatedConfig = pmssQbittorrentDiskIoTypeRemove($updatedConfig);
                if (!is_string($updatedConfig)) return null;
            }
            foreach (pmssQbittorrentManagedConfigEntries($family) as $entry) {
                $updatedConfig = pmssQbittorrentConfigUpsert($updatedConfig, $entry['section'], $entry['key'], $entry['value']);
                if (!is_string($updatedConfig)) {
                    return null;
                }
            }

            return $updatedConfig;
        },
        $configFile
    );
}

/**
 * Update the qBittorrent WebUI password hash inside the config file.
 */
function pmssQbittorrentApplyPassword(string $username, string $password, ?string $configFile = null): bool
{
    return pmssQbittorrentConfigMutate(
        $username,
        static function (string $config) use ($password): ?string {
            return pmssQbittorrentConfigUpsert(
                $config,
                'Preferences',
                'WebUI\\Password_PBKDF2',
                pmssQbittorrentPasswordHashGenerate($password)
            );
        },
        $configFile
    );
}

/**
 * Update qBittorrent config with a new WebUI password hash.
 */
function pmssUpdateQbittorrentPassword(string $username, string $password): bool
{
    return pmssQbittorrentApplyPassword($username, $password);
}

/** Write the user-owned config with the account's privileges. */
function pmssUpdateQbittorrentPasswordAsUser(string $username, string $password): bool
{
    require_once __DIR__.'/qbittorrentStoppedConfig.php';
    $identity = function_exists('posix_getpwnam') ? @posix_getpwnam($username) : false;
    if (!is_array($identity) || !function_exists('posix_geteuid')) {
        return false;
    }
    $home = dirname(dirname(dirname(pmssQbittorrentConfigPath($username))));
    if (posix_geteuid() === (int) $identity['uid']) {
        return pmssQbittorrentPasswordApplyStopped($username, $home, $password);
    }
    if (posix_geteuid() !== 0) {
        return false;
    }

    // Load the operator library before the child switches to the account identity.
    $code = 'require_once '.var_export(__DIR__.'/qbittorrentStoppedConfig.php', true).";\n".<<<'PHP'
$account = posix_getpwnam($argv[1]);
if (!is_array($account)
    || !posix_initgroups($argv[1], (int) $account['gid'])
    || !posix_setgid((int) $account['gid'])
    || !posix_setuid((int) $account['uid'])) exit(1);
$password = fgets(STDIN);
if (!is_string($password)) exit(1);
$password = preg_replace('/\r?\n$/', '', $password);
exit(is_string($password) && $password !== ''
    && pmssQbittorrentPasswordApplyStopped($argv[1], $argv[2], $password) ? 0 : 1);
PHP;
    $output = [];
    $command = pmssBuildCommand(PHP_BINARY, ['-r', $code, $username, $home]);
    $status = pmssCredentialProcessRun($command, $password, $output, false, 45);
    if ($status !== 0) error_log('PMSS qBittorrent: password synchronization failed; see the account notice.');
    return $status === 0;
}
