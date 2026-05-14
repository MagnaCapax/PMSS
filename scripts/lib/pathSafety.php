<?php
/**
 * Shared filesystem path safety predicates.
 *
 * These helpers centralize segment-by-segment traversal checks so writers can
 * reject symlinked ancestors and dot segments before touching managed paths.
 *
 * @license GPL-3.0-only
 */

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
        if (is_link($current)) {
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
