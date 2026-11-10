<?php
/**
 * PMSS-managed per-user lighttpd reverse proxy fragments.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/userFileWrite.php';
require_once __DIR__.'/proxyPorts.php';
require_once __DIR__.'/proxyRoutes.php';

/** Return the regex strings used by lighttpd URL conditionals. */
function pmssLighttpdProxyFragmentUrlConditionPatterns(string $fragment): array
{
    $matches = [];
    if (preg_match_all('/^[ \t]*\$HTTP\["url"\][ \t]*=~[ \t]*"([^"\r\n]+)"[ \t]*\{/m', $fragment, $matches) < 1) {
        return [];
    }

    return array_values(array_unique($matches[1]));
}

/**
 * Detect an exact URL-conditional collision in a sibling fragment.
 *
 * A null result means the directory could not be scanned safely and callers
 * must not create or replace the managed fragment.
 */
function pmssLighttpdProxyFragmentSiblingConflict(string $managedPath, string $managedFragment): ?bool
{
    $patterns = pmssLighttpdProxyFragmentUrlConditionPatterns($managedFragment);
    $directory = dirname($managedPath);
    if ($patterns === [] || !pmssPathTargetIsSafe($directory, true)) {
        return $patterns === [] ? false : null;
    }

    $siblings = @glob($directory.'/*.conf');
    if ($siblings === false) {
        return null;
    }
    foreach ($siblings as $siblingPath) {
        if ($siblingPath === $managedPath) {
            continue;
        }
        if (!pmssPathTargetIsSafe($siblingPath, false) || !is_readable($siblingPath)) {
            return null;
        }
        $siblingFragment = @file_get_contents($siblingPath);
        if (!is_string($siblingFragment)) {
            return null;
        }
        if (array_intersect($patterns, pmssLighttpdProxyFragmentUrlConditionPatterns($siblingFragment)) !== []) {
            return true;
        }
    }

    return false;
}

function pmssLighttpdWriteManagedProxyFragment(string $proxyName, string $user, int $port, string $path): bool
{
    $fragment = pmssLighttpdManagedProxyFragment($proxyName, $user, $port);
    $siblingConflict = pmssLighttpdProxyFragmentSiblingConflict($path, $fragment);
    if ($siblingConflict !== false) {
        if ($siblingConflict === null) {
            fwrite(STDERR, "[user:{$user}] Skipping {$proxyName} lighttpd fragment; sibling fragments could not be scanned safely\n");
            return false;
        }
        if (pmssPathExistsOrLink($path)
            && (!pmssUserFilePathIsSafe($path) || !is_file($path) || !@unlink($path))
        ) {
            fwrite(STDERR, "[user:{$user}] Failed to yield {$proxyName} lighttpd fragment to an existing URL conditional\n");
            return false;
        }
        fwrite(STDERR, "[user:{$user}] Skipping {$proxyName} lighttpd fragment; sibling fragment already defines its URL conditional\n");
        return true;
    }
    if (pmssWriteUserFile($path, $fragment, $user, 0640)) {
        return true;
    }
    fwrite(STDERR, "[user:{$user}] Failed to write {$proxyName} lighttpd fragment\n");

    return false;
}
