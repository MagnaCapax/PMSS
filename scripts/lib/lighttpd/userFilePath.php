<?php
/** Managed path predicates and directory preparation. @license GPL-3.0-only */

/** Lexical account-home boundary used to reject paths that cannot be classified. */
function pmssManagedAccountHomePath(string $path): bool
{
    $root = rtrim((string) (getenv('PMSS_HOME_DIR') ?: '/home'), '/');
    if (strpos($path, $root.'/') !== 0) return false;
    // The account writer and root stage keep root out of account-controlled trees.
    // A non-root caller has no privilege to protect: it writes with its own UID,
    // including inside its own home, where it cannot publish root-owned files.
    return !function_exists('posix_geteuid') || @posix_geteuid() === 0;
}

function pmssUserFilePathIsSafe(string $path): bool
{
    return pmssPathTargetIsSafe($path, false, true);
}

/** Keep customer-readable status artifacts inside their account home. */
function pmssUserStatusPathIsSafe(string $home, string $path): bool { return $path !== '' && pmssPathTargetIsSafe($path, false, true) && pmssPathWithinResolvedRoot($path, $home); }

/**
 * Confirm a safe path resolves inside an already-existing directory root.
 */
function pmssPathWithinRootIsSafe(string $path, string $rootPath, bool $directoryTarget = false): bool
{
    $rootPath = rtrim($rootPath, '/');
    if ($rootPath === '') {
        $rootPath = '/';
    }

    if ($rootPath !== '/' && !pmssPathTargetIsSafe($rootPath, true)) {
        return false;
    }
    if (!pmssPathTargetIsSafe($path, $directoryTarget)) {
        return false;
    }

    $resolvedRoot = @realpath($rootPath);
    $resolvedPath = @realpath($path);
    if ($resolvedRoot === false || $resolvedPath === false) {
        return false;
    }
    if ($resolvedRoot === '/') {
        return $resolvedPath !== '' && $resolvedPath[0] === '/';
    }

    return $resolvedPath === $resolvedRoot || strpos($resolvedPath, $resolvedRoot.'/') === 0;
}

/**
 * Ensure a directory exists when the target path is safe.
 */
function pmssEnsureSafeDir(string $path, int $mode): bool
{
    if (pmssManagedAccountHomePath($path)) {
        $context = pmssRootStageContext($path);
        $identity = pmssAccountHomeIdentity($path);
        if ($identity !== null && @posix_geteuid() === $identity[2]) {
            require_once __DIR__.'/../user/directories.php';
            return pmssAccountDirectoryEnsure($identity[1], $identity[0], $path, $mode, 0755);
        }
        if ($context === null) return false;
        require_once __DIR__.'/../user/directories.php';
        return pmssAccountDirectoryEnsure($context[2], $context[1], $path, $mode, 0755);
    }
    if (!pmssPathTargetIsSafe($path, true)) {
        return false;
    }

    if (!pmssDirEnsureExists($path, $mode)) {
        return false;
    }

    // A created directory is not ready for callers until its requested mode applies.
    return @chmod($path, $mode) && is_dir($path) && !is_link($path);
}

/** Ensure each managed directory exists, reporting unsafe paths through the callback. */
function pmssManagedDirsEnsure(array $directories, callable $failureLogger): void { foreach ($directories as $dir => $mode) { pmssEnsureSafeDir((string) $dir, (int) $mode) || $failureLogger((string) $dir); } }
