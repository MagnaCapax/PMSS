<?php
/** Checked targets and ordered command execution for system permission policy.
 * @license GPL-3.0-only
 */

/** Skip when the path is a symlink to avoid hardening unexpected destinations. */
function pmssSkipSymlink(string $path): bool
{
    if (is_link($path)) {
        logmsg(sprintf('Skipping %s: symlink detected', $path));
        return true;
    }
    return false;
}

/** Preserve distinct directory/file checks behind the common symlink refusal. */
function pmssPermissionTargetExists(string $path, bool $directory = true): bool
{
    return !pmssSkipSymlink($path) && ($directory ? is_dir($path) : is_file($path));
}

/** Enforce root ownership on a target path when present. */
function pmssEnsureRootOwnership(string $path, array &$exitCodes): void
{
    if (pmssSkipSymlink($path)) {
        return;
    }
    if (!file_exists($path)) {
        logmsg(sprintf('Skipping ownership enforcement; missing %s', $path));
        return;
    }
    $exitCodes[] = runStep(
        sprintf('Ensuring %s ownership is root:root', $path),
        'chown root:root '.escapeshellarg($path)
    );
}

/** Check once, optionally enforce ownership, then retain every command's result. */
function pmssPermissionStepsRun(string $path, array $steps, array &$exitCodes, bool $rootOwned = false, bool $directory = true): void
{
    if (!pmssPermissionTargetExists($path, $directory)) {
        return;
    }
    if ($rootOwned) pmssEnsureRootOwnership($path, $exitCodes);
    foreach ($steps as [$message, $command]) {
        $exitCodes[] = runStep($message, $command);
    }
}
