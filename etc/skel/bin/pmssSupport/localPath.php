<?php
/** Customer-local support dependencies; no operator bootstrap. @license GPL-3.0-only */

require_once __DIR__.'/localFilesystem.php';

/** Local copy of scripts/lib/pathSafety.php::pmssResolvePathFromEnv; preserve its guards and behavior. */
function pmssSupportLocalResolvePathFromEnv(string $envKey, string $default): string
{
    $value = getenv($envKey);
    $value = ($value === false || $value === '') ? $default : $value;
    $value = rtrim($value, '/');
    return $value !== '' ? $value : rtrim($default, '/');
}

/** Local copy of scripts/lib/pathSafety.php::pmssPathIsFhsStandardSymlink; preserve its guards and behavior. */
function pmssSupportLocalPathIsFhsStandardSymlink(string $linkPath): bool
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

/** Local copy of scripts/lib/pathSafety.php::pmssPathRelativeStringIsSafe; preserve its guards and behavior. */
function pmssSupportLocalPathRelativeStringIsSafe(string $path, array $options = []): bool
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

/** Local copy of scripts/lib/pathSafety.php::pmssPathSegmentsAreSafe; preserve its guards and behavior. */
function pmssSupportLocalPathSegmentsAreSafe(
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
        if (is_link($current) && !pmssSupportLocalPathIsFhsStandardSymlink($current)) {
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

/** Local copy of scripts/lib/pathSafety.php::pmssPathTargetIsSafe; preserve its guards and behavior. */
function pmssSupportLocalPathTargetIsSafe(
    string $path,
    bool $directoryTarget,
    bool $requireParentDirectory = false,
    bool $allowEmptySegments = true
): bool {
    $path = rtrim($path, '/');
    if (!pmssSupportLocalPathSegmentsAreSafe($path, false, $allowEmptySegments, true, $directoryTarget)) {
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

