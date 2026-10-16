<?php
/** Root reads only the installed skeleton, never a tenant-controlled copy. */
function pmssToggleAppCatalogRead(): array
{
    $path = dirname(__DIR__, 2).'/etc/skel/www/toggleAppCatalog.php';
    if (getenv('PMSS_TEST_MODE') === '1' && getenv('PMSS_TOGGLE_APP_CATALOG_PATH')) {
        $path = getenv('PMSS_TOGGLE_APP_CATALOG_PATH');
    }
    clearstatcache(true, $path);
    clearstatcache(true, dirname($path));
    if (!is_file($path) || is_link($path) || is_link(dirname($path))
        || (fileperms($path) & 0022) !== 0 || (fileperms(dirname($path)) & 0022) !== 0
        || (function_exists('posix_geteuid') && posix_geteuid() === 0
            && (fileowner($path) !== 0 || fileowner(dirname($path)) !== 0))) {
        throw new RuntimeException('Unsafe toggle-app catalog');
    }
    $catalog = require $path;
    if (!is_array($catalog)) throw new RuntimeException('Invalid toggle-app catalog');
    return $catalog;
}
