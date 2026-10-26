<?php
/** User lifecycle web-root validation, suspension markers, and backup selection.
 * @license GPL-3.0-only
 */

/** @return array<string,string> */
function pmssUserLifecycleRequireUserRoots(array $argv, string $scriptName, string $action): array
{
    $username = (string) ($argv[1] ?? '');
    if ($username === '') {
        die($scriptName." USERNAME\n");
    }
    ['username' => $username, 'homeDir' => $homeDir] = userFilesystem::requireCliUserHome(
        $username,
        $action,
        "Invalid username: %s\n",
        "User home %s missing\n"
    );

    return array(
        'username' => $username,
        'homeDir' => $homeDir,
        'activeRoot' => $homeDir.'/www',
        'disabledRoot' => $homeDir.'/www-disabled',
    );
}

/** Validate active/suspended web roots and return their marker state. */
function pmssUserLifecycleRequireWebRootState(string $action, string $username, string $homeDir, string $activeRoot, string $disabledRoot): array
{
    $activeRootExists = pmssPathExistsOrLink($activeRoot);
    $disabledRootExists = pmssPathExistsOrLink($disabledRoot);
    $rootSpecs = array(
        array('exists' => $activeRootExists, 'path' => $activeRoot, 'basename' => 'www', 'message' => 'Refusing unsafe active web root'),
        array('exists' => $disabledRootExists, 'path' => $disabledRoot, 'basename' => 'www-disabled', 'message' => 'Refusing unsafe suspended web root'),
    );
    foreach ($rootSpecs as $spec) {
        if (pmssUserLifecycleWebRootPathIsSafe($homeDir, $spec['path'], $spec['basename'], $spec['exists'])) continue;
        pmssUserLifecycleContextLogStatusMessage($action, 'validate_web_root', $username, 'ERR', $spec['message'], array('path' => $spec['path']));
        die($spec['message']."\n");
    }

    return array('activeRootExists' => $activeRootExists, 'disabledRootExists' => $disabledRootExists);
}

/**
 * Validate a lifecycle web root before rename, restore, or marker decisions.
 */
function pmssUserLifecycleWebRootPathIsSafe(string $homeDir, string $path, string $expectedBasename, bool $requireExistingDir): bool
{
    $homeDir = rtrim($homeDir, '/');
    if (
        $homeDir === ''
        || $path === ''
        || $expectedBasename === ''
        || preg_match('/[\r\n\0\/]/', $expectedBasename) === 1
        || pmssFilesystemPathHasNulByte($homeDir)
        || pmssFilesystemPathHasNulByte($path)
        || is_link($homeDir)
    ) {
        return false;
    }

    $realHome = realpath($homeDir);
    if ($realHome === false || $realHome !== $homeDir || dirname($path) !== $homeDir
        || basename($path) !== $expectedBasename || is_link($path)) {
        return false;
    }
    if (!file_exists($path)) {
        return !$requireExistingDir;
    }

    $realPath = realpath($path);
    return $realPath !== false && $realPath === $path && is_dir($realPath);
}

/**
 * Mirror the best-effort suspended flag into the durable user config store.
 */
function pmssUserLifecycleSetSuspendedState(string $username, bool $suspended, ?callable $stateWriter = null): bool
{
    $writer = $stateWriter ?? static function (string $writerUsername, bool $writerSuspended): bool {
        static $store = null;
        if ($store === null) {
            $store = new UserConfigStore();
        }

        return $store->setSuspended($writerUsername, $writerSuspended);
    };

    return (bool) $writer($username, $suspended);
}

/**
 * Mirror the canonical `www-disabled` marker into the durable config store.
 */
function pmssUserLifecycleSyncSuspendedState(string $username, string $disabledRoot, ?callable $stateWriter = null): bool
{
    $suspended = is_dir($disabledRoot);
    pmssUserLifecycleSetSuspendedState($username, $suspended, $stateWriter);
    return $suspended;
}

/**
 * Validate that a suspended backup candidate is a direct non-symlinked home child.
 */
function pmssUserLifecycleSuspendedBackupCandidateIsSafe(string $homeDir, string $candidate): bool
{
    $homeDir = rtrim($homeDir, '/');
    return $homeDir !== ''
        && !is_link($candidate)
        && is_dir($candidate)
        && dirname($candidate) === $homeDir
        && strpos(basename($candidate), 'www-suspended-') === 0;
}

/**
 * Return whether a validated web-root candidate contains user web content.
 */
function pmssUserLifecycleWebRootContainsUserContent(string $candidate): bool
{
    if (is_dir($candidate.'/rutorrent') || is_file($candidate.'/index.php')) {
        return true;
    }

    $entries = pmssDirectoryEntriesRead($candidate);
    if (empty($entries)) {
        return false;
    }
    foreach ($entries as $entry) {
        if ($entry !== 'index.html' && $entry !== 'public') {
            return true;
        }
    }

    $publicEntries = pmssDirectoryEntriesRead($candidate.'/public');
    return $publicEntries === false || count(array_diff($publicEntries, array('index.html'))) > 0;
}

/**
 * Remove only an empty, validated suspended marker.
 */
function pmssUserLifecycleClearEmptySuspendedMarker(string $homeDir, string $disabledRoot): bool
{
    if (!pmssUserLifecycleWebRootPathIsSafe($homeDir, $disabledRoot, 'www-disabled', true)
        || pmssUserLifecycleWebRootContainsUserContent($disabledRoot)) {
        return false;
    }

    return @rmdir($disabledRoot);
}

/**
 * Find the newest suspended web backup that still contains user content.
 */
function pmssUserLifecycleFindSuspendedBackup(string $homeDir): ?string
{
    $homeDir = rtrim($homeDir, '/');
    $candidates = glob($homeDir.'/www-suspended-*', GLOB_NOSORT);
    if (empty($candidates)) {
        return null;
    }
    $bestPath = null;
    $bestMtime = 0;
    foreach ($candidates as $candidate) {
        if (!pmssUserLifecycleSuspendedBackupCandidateIsSafe($homeDir, $candidate)
            || !pmssUserLifecycleWebRootContainsUserContent($candidate)) {
            continue;
        }
        $mtime = @filemtime($candidate);
        $mtime = $mtime === false ? 0 : $mtime;
        if ($bestPath === null || $mtime > $bestMtime || ($mtime === $bestMtime && strcmp($candidate, $bestPath) > 0)) {
            $bestPath = $candidate;
            $bestMtime = $mtime;
        }
    }

    return $bestPath;
}
