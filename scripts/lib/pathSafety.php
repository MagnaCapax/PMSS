<?php
/**
 * Shared filesystem path safety predicates.
 *
 * These helpers centralize segment-by-segment traversal checks so writers can
 * reject symlinked ancestors and dot segments before touching managed paths.
 *
 * @license GPL-3.0-only
 */

/** Resolve a filesystem path from an environment variable with a default. */
function pmssResolvePathFromEnv(string $envKey, string $default): string
{
    $value = getenv($envKey);
    $value = ($value === false || $value === '') ? $default : $value;
    $value = rtrim($value, '/');
    return $value !== '' ? $value : rtrim($default, '/');
}

/** Locate the base directory for skeleton files. */
function pmssSkeletonBase(): string
{
    return pmssResolvePathFromEnv('PMSS_SKEL_DIR', '/etc/skel');
}

/** Normalize directory paths while preserving `/` and intentional empty overrides. */
function pmssDirPathNormalize(string $path): string { $trimmed = rtrim($path, '/'); return $trimmed !== '' ? $trimmed : ($path !== '' ? '/' : ''); }
/** Resolve a directory path from an explicit override or env-backed default. */
function pmssDirPathResolve(?string $override, string $envKey, string $default): string { return pmssDirPathNormalize((string) ($override !== null ? $override : pmssResolvePathFromEnv($envKey, $default))); }

/**
 * FHS-standard root-owned symlinks that are safe to traverse.
 *
 * Debian (and most modern Linux distros) ship /var/run as a symlink to /run
 * and /var/lock as a symlink to /run/lock. These are root-owned, OS-managed,
 * and present on every PMSS host. Rejecting them would block every script
 * that writes to /var/run/pmss/* — see the 2026-05-23 heshtok regression
 * where resourceLog.php, resourceStats.php, trafficIngressLog.php, and
 * the traffic-storage helpers all failed silently for 8 days, accumulating
 * multi-gigabyte error logs, because pmssPathSegmentsAreSafe rejected every
 * /var/run/* path.
 *
 * Both the symlink path AND its target are verified; an attacker-replaced
 * symlink whose target differs from the FHS standard is still rejected.
 */
function pmssPathIsFhsStandardSymlink(string $linkPath): bool
{
    static $standard = [
        '/var/run'  => '/run',
        '/var/lock' => '/run/lock',
    ];
    if (!isset($standard[$linkPath])) {
        return false;
    }
    $target = @readlink($linkPath);
    return is_string($target) && $target === $standard[$linkPath];
}

/**
 * Validate lexical absolute path strings without touching the filesystem.
 */
function pmssPathAbsoluteStringIsSafe(string $path, array $options = []): bool
{
    $allowRoot = isset($options['allowRoot']) ? (bool) $options['allowRoot'] : false;
    $allowTrailingSlash = array_key_exists('allowTrailingSlash', $options) ? (bool) $options['allowTrailingSlash'] : true;
    $allowWhitespace = array_key_exists('allowWhitespace', $options) ? (bool) $options['allowWhitespace'] : true;
    $requiredPrefix = isset($options['requiredPrefix']) ? (string) $options['requiredPrefix'] : '';

    if ($path === ''
        || $path[0] !== '/'
        || strpos($path, "\0") !== false
        || (!$allowWhitespace && preg_match('/\s/', $path) === 1)
        || (!$allowTrailingSlash && substr($path, -1) === '/')
        || ($requiredPrefix !== '' && strpos($path, $requiredPrefix) !== 0)
    ) {
        return false;
    }
    if ($path === '/') return $allowRoot;

    foreach (explode('/', trim($path, '/')) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return false;
        }
    }

    return true;
}

/**
 * Validate relative path strings before joining them to a trusted root.
 */
function pmssPathRelativeStringIsSafe(string $path, array $options = []): bool
{
    if ($path === '' || $path[0] === '/' || strpos($path, "\0") !== false) return false;
    if (empty($options['allowControlChars']) && preg_match('/[\r\n]/', $path) === 1) return false;
    if (!empty($options['rejectDotDotSubstring']) && strpos($path, '..') !== false) return false;

    $allowEmptySegments = !empty($options['allowEmptySegments']); $allowDotSegments = !empty($options['allowDotSegments']);
    foreach (explode('/', $path) as $segment) {
        if ($segment === '..' || ($segment === '' && !$allowEmptySegments) || ($segment === '.' && !$allowDotSegments)) return false;
    }

    return true;
}

/**
 * Validate path segments while preserving caller-specific absolute/leaf policy.
 */
function pmssPathSegmentsAreSafe(
    string $path,
    bool $allowRelative = false,
    bool $allowEmptySegments = true,
    bool $validateLeafType = false,
    bool $directoryTarget = false
): bool {
    $path = rtrim($path, '/');
    if ($path === '' || strpos($path, "\0") !== false) {
        return false;
    }
    if (!$allowRelative && $path[0] !== '/') {
        return false;
    }

    $absolute = $path[0] === '/';
    $segments = explode('/', ltrim($path, '/'));
    $current = '';
    $lastIndex = count($segments) - 1;
    foreach ($segments as $index => $segment) {
        if ($segment === '') {
            if (!$allowEmptySegments) {
                return false;
            }
            continue;
        }
        if ($segment === '.' || $segment === '..') {
            return false;
        }

        $current = $current === ''
            ? ($absolute ? '/'.$segment : $segment)
            : $current.'/'.$segment;
        if (is_link($current) && !pmssPathIsFhsStandardSymlink($current)) {
            return false;
        }

        $isLeaf = $index === $lastIndex;
        if (!$isLeaf && file_exists($current) && !is_dir($current)) {
            return false;
        }
        if ($validateLeafType
            && $isLeaf
            && file_exists($current)
            && ($directoryTarget ? !is_dir($current) : !is_file($current))) {
            return false;
        }
    }

    return true;
}

/**
 * Return shell-safe existing targets while rejecting symlinked path segments.
 */
function pmssPathShellTarget(string $path): ?string
{
    $hasGlob = strpbrk($path, '*?[]') !== false;

    if ($hasGlob) {
        $matches = glob($path);
        if ($matches === false || $matches === []) {
            return null;
        }

        $targets = [];
        foreach ($matches as $match) {
            if (file_exists($match) && pmssPathSegmentsAreSafe($match)) {
                $targets[] = escapeshellarg($match);
            }
        }

        return $targets === [] ? null : implode(' ', $targets);
    }

    return file_exists($path) && pmssPathSegmentsAreSafe($path) ? escapeshellarg($path) : null;
}

/**
 * Validate a filesystem target and reject symlinked path segments.
 */
function pmssPathTargetIsSafe(
    string $path,
    bool $directoryTarget,
    bool $requireParentDirectory = false,
    bool $allowEmptySegments = true
): bool {
    $path = rtrim($path, '/');
    if (!pmssPathSegmentsAreSafe($path, false, $allowEmptySegments, true, $directoryTarget)) {
        return false;
    }

    if ($requireParentDirectory) {
        $directory = dirname($path);
        if (!is_dir($directory) || is_link($directory)) {
            return false;
        }
    }

    return true;
}

/** Confirm that a candidate path lives below an existing resolved root. */
function pmssPathWithinResolvedRoot(string $path, string $root): bool
{
    $root = rtrim($root, '/');
    if (!pmssPathAbsoluteStringIsSafe($root, ['allowRoot' => false])
        || $path === ''
        || $path[0] !== '/'
        || strpos($path, "\0") !== false) {
        return false;
    }

    $realRoot = realpath($root);
    $realParent = realpath(dirname($path));
    if ($realRoot === false || $realParent === false) {
        return false;
    }

    $candidate = rtrim($realParent, '/').'/'.basename($path);
    return strpos($candidate, rtrim($realRoot, '/').'/') === 0;
}
