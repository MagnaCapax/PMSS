<?php
/**
 * Reconciliation helpers for PMSS-managed per-user nginx configs.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../lighttpd/userFileWrite.php';

/** Resolve one trusted reconciliation directory without allowing an empty root. */
function pmssCreateNginxConfigContextDir(array $ctx, string $key, string $default): string
{
    $path = rtrim((string) ($ctx[$key] ?? $default), '/');
    return $path !== '' ? $path : $default;
}

/** Return every PMSS-managed nginx path for one validated user. */
function pmssCreateNginxConfigManagedUserPaths(string $user, array $ctx): array
{
    $nginxUsersDir = pmssCreateNginxConfigContextDir($ctx, 'nginxUsersDir', '/etc/nginx/users');
    $subdomainConfigDir = pmssCreateNginxConfigContextDir($ctx, 'subdomainConfigDir', '/etc/nginx/conf.d');

    $paths = [
        'user' => $nginxUsersDir.'/'.$user,
        // Retired generated route: always reconcile it, even without subdomains.
        'private' => $subdomainConfigDir.'/pmss-user-'.$user.'-hash.conf',
    ];
    if (!empty($ctx['subdomainEnabled'])) {
        $paths['public'] = $subdomainConfigDir.'/pmss-user-'.$user.'.conf';
    }

    return $paths;
}

/** Remove a stale managed nginx config without following unsafe filesystem edges. */
function pmssCreateNginxConfigRemoveFile(string $path, string $user, string $label): bool
{
    if (!pmssUserFilePathIsSafe($path)) {
        pmssCreateNginxConfigLogSkippedUser($user, 'unsafe '.$label.' path ('.$path.')');
        return false;
    }
    if (!file_exists($path)) {
        return true;
    }
    if (!pmssRegularFilePathIsReadable($path)) {
        pmssCreateNginxConfigLogSkippedUser($user, 'refusing to remove non-regular '.$label.' ('.$path.')');
        return false;
    }
    if (@unlink($path)) {
        return true;
    }

    pmssCreateNginxConfigLogSkippedUser($user, 'failed to remove '.$label.' ('.$path.')');
    return false;
}

/** Remove one user's obsolete config variants after a successful decision. */
function pmssCreateNginxConfigReconcileStaleUserFiles(string $user, array $ctx, array $keepPaths): bool
{
    $keep = array_fill_keys($keepPaths, true);
    $success = true;
    foreach (pmssCreateNginxConfigManagedUserPaths($user, $ctx) as $label => $path) {
        if (isset($keep[$path])) {
            continue;
        }
        if (!pmssCreateNginxConfigRemoveFile($path, $user, 'stale '.$label.' config')) {
            $success = false;
        }
    }
    return $success;
}

/** Remove managed paths not owned by the already-selected current user set. */
function pmssCreateNginxConfigPruneOrphans(array $users, array $ctx): bool
{
    $expected = [];
    foreach ($users as $user) {
        $user = trim((string) $user);
        if ($user === '' || !pmssValidateUsername($user)) {
            continue;
        }
        foreach (pmssCreateNginxConfigManagedUserPaths($user, $ctx) as $label => $path) {
            if ($label === 'private') continue;
            $expected[$path] = true;
        }
    }

    $patterns = [pmssCreateNginxConfigContextDir($ctx, 'nginxUsersDir', '/etc/nginx/users').'/*'];
    if (!empty($ctx['subdomainEnabled'])) {
        $patterns[] = pmssCreateNginxConfigContextDir($ctx, 'subdomainConfigDir', '/etc/nginx/conf.d').'/pmss-user-*.conf';
    } else {
        $patterns[] = pmssCreateNginxConfigContextDir($ctx, 'subdomainConfigDir', '/etc/nginx/conf.d').'/pmss-user-*-hash.conf';
    }

    $success = true;
    foreach ($patterns as $pattern) {
        $matches = glob($pattern);
        if (!is_array($matches)) {
            continue;
        }
        sort($matches, SORT_STRING);
        foreach ($matches as $path) {
            // Only remove the exact generated hash filename, never lookalikes.
            if (substr($path, -10) === '-hash.conf' &&
                (preg_match('/^pmss-user-(.+)-hash\.conf$/D', basename($path), $match) !== 1 || !pmssValidateUsername($match[1]))) {
                continue;
            }
            if (isset($expected[$path])) {
                continue;
            }
            if (!pmssCreateNginxConfigRemoveFile($path, basename($path), 'orphan managed config')) {
                $success = false;
            }
        }
    }
    return $success;
}

/** Confirm the primary path route is a safe regular file nginx can read. */
function pmssCreateNginxConfigUserRouteIsServiceable(string $user, array $ctx): bool
{
    $path = pmssCreateNginxConfigManagedUserPaths($user, $ctx)['user'];
    return pmssUserFilePathIsSafe($path) && pmssRegularFilePathIsReadable($path) && is_readable($path);
}
