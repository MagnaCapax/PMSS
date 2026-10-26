<?php
/** ARR config helpers; loaded through arr.php. @license GPL-3.0-only */
/**
 * Reject config values that could break shell/file boundaries.
 */
function pmssArrIsSafeConfigValue(string $value): bool
{
    return $value !== '' && preg_match('/[\r\n\0]/', $value) !== 1;
}

/**
 * Reject absolute paths that could resolve outside the intended target.
 */
function pmssArrInstallPathIsSafe(string $path): bool
{
    require_once dirname(__DIR__, 3).'/pathSafety.php';
    // The updater replaces this directory; never allow top-level
    // targets such as /opt, /home, or /tmp to pass config validation.
    return pmssPathAbsoluteStringIsSafe($path)
        && count(explode('/', trim($path, '/'))) >= 2
        && pmssPathTargetIsSafe($path, true, false);
}

/**
 * Normalize required config and fail closed on unsafe runtime inputs.
 */
function pmssArrNormalizeConfig(array $config, callable $log): ?array
{
    foreach (['app', 'install_path', 'releases_url', 'asset_pattern', 'extract_dir'] as $key) {
        if (!is_string($config[$key] ?? null) || !pmssArrIsSafeConfigValue($config[$key])) {
            $log('Invalid updater configuration: '.$key);
            return null;
        }
    }

    if (!pmssArrInstallPathIsSafe($config['install_path'])) {
        $log('Invalid updater configuration: install_path');
        return null;
    }
    if ($config['extract_dir'] === '.'
        || $config['extract_dir'] === '..'
        || basename($config['extract_dir']) !== $config['extract_dir']
    ) {
        $log('Invalid updater configuration: extract_dir');
        return null;
    }
    if (@preg_match($config['asset_pattern'], '') === false) {
        $log('Invalid updater configuration: asset_pattern');
        return null;
    }

    $config['user_agent'] = is_string($config['user_agent'] ?? null)
        && pmssArrIsSafeConfigValue($config['user_agent'])
        ? $config['user_agent']
        : 'PMSS-ARR';

    return $config;
}
