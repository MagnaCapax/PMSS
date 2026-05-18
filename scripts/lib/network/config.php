<?php
/**
 * Network configuration helpers shared by setup scripts.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';

function networkLoadConfig(): array
{
    $config = file_exists($path = pmssResolvePathFromEnv('PMSS_NETWORK_CONFIG', '/etc/seedbox/config/network'))
        ? include $path
        : null;
    return is_array($config) ? $config : [];
}

/**
 * Validate one local network entry before it reaches rendered shell policy.
 */
function networkLocalnetEntryIsValid(string $entry): bool
{
    $entry = trim($entry);
    if ($entry === '' || preg_match('/[\s;&|`$<>\\\\\0]/', $entry) === 1) {
        return false;
    }

    $parts = explode('/', $entry, 2);
    if (filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false;
    }
    return count($parts) === 1 || (
        $parts[1] !== ''
        && ctype_digit($parts[1])
        && (int) $parts[1] >= 0
        && (int) $parts[1] <= 32
        && (string) (int) $parts[1] === $parts[1]
    );
}

function networkLoadLocalnets(): array
{
    $path = pmssResolvePathFromEnv('PMSS_LOCALNET_FILE', '/etc/seedbox/config/localnet');
    $loadDefaultLocalnets = static function () use ($path): array {
        $hostname = getenv('PMSS_HOSTNAME');
        if (!is_string($hostname) || trim($hostname) === '') {
            $hostname = function_exists('gethostname') ? (string) @gethostname() : (string) php_uname('n');
        }

        $hostname = strtolower(trim($hostname));
        if ($hostname === '' || preg_match('/(^|\\.)pulsedmedia\\.com$/', $hostname) !== 1) {
            return [];
        }

        $default = ['185.148.0.0/22'];
        $directory = dirname($path);
        $realDirectory = realpath($directory);
        if (
            $path !== ''
            && $path[0] === '/'
            && preg_match('/[\r\n\0]/', $path) !== 1
            && $directory !== ''
            && $realDirectory !== false
            && pmssDirPathNormalize($realDirectory) === pmssDirPathNormalize($directory)
            && @file_put_contents($path, implode("\n", $default)."\n") !== false
        ) {
            @chmod($path, 0644);
        }
        return $default;
    };

    if (!file_exists($path)) {
        return $loadDefaultLocalnets();
    }

    $contents = pmssReadRegularFileContents($path);
    if ($contents === null) {
        return [];
    }

    $cfg = trim($contents);
    if ($cfg === '') {
        return $loadDefaultLocalnets();
    }

    $valid = [];
    foreach (preg_split('/\r?\n/', $cfg, -1, PREG_SPLIT_NO_EMPTY) as $localnet) {
        $localnet = trim((string) $localnet);
        if (networkLocalnetEntryIsValid($localnet)) {
            $valid[] = $localnet;
        }
    }
    return $valid;
}
