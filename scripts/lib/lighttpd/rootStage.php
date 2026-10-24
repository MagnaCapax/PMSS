<?php
/** Publish root-owned account-home files from a private stage beside the home. @license GPL-3.0-only */

require_once __DIR__.'/../pathSafety.php';
require_once __DIR__.'/../runtime/filesystem.php';

/** Resolve an account home independently of whether the caller already is that account. */
function pmssAccountHomeIdentity(string $path): ?array
{
    $root = rtrim((string) (getenv('PMSS_HOME_DIR') ?: '/home'), '/');
    if (!pmssPathAbsoluteStringIsSafe($root, ['allowRoot' => false])
        || strpos($path, $root.'/') !== 0 || !pmssPathSegmentsAreSafe($path)) return null;
    $parts = explode('/', substr($path, strlen($root) + 1));
    if (count($parts) < 2 || $parts[0] === '') return null;
    $home = $root.'/'.$parts[0];
    $account = function_exists('posix_getpwnam') ? @posix_getpwnam($parts[0]) : false;
    $entry = @lstat($home);
    if (!is_array($account) || !is_array($entry) || ($entry['mode'] & 0170000) !== 0040000
        || $entry['uid'] !== $account['uid']) return null;
    return [$home, $parts[0], $account['uid']];
}

/** Return the home and its root-owned parent for a managed account path. */
function pmssRootStageContext(string $path): ?array
{
    $root = rtrim((string) (getenv('PMSS_HOME_DIR') ?: '/home'), '/');
    if (!pmssPathAbsoluteStringIsSafe($root, ['allowRoot' => false])
        || strpos($path, $root.'/') !== 0 || !pmssPathSegmentsAreSafe($path)) return null;
    $relative = substr($path, strlen($root) + 1);
    $parts = explode('/', $relative);
    if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') return null;
    $home = $root.'/'.$parts[0];
    $anchor = @lstat($root);
    $account = function_exists('posix_getpwnam') ? @posix_getpwnam($parts[0]) : false;
    $homeStat = @lstat($home);
    if (!is_array($anchor) || ($anchor['mode'] & 0170000) !== 0040000
        || $anchor['uid'] !== 0 || ($anchor['mode'] & 0022) !== 0
        || !is_array($account) || !is_array($homeStat)
        || ($homeStat['mode'] & 0170000) !== 0040000
        || $homeStat['uid'] !== $account['uid']) return null;
    return [$root, $home, $parts[0]];
}

/** Discard only old empty stage directories created under the trusted parent. */
function pmssRootStageSweep(string $root): void
{
    $parent = @lstat($root);
    if (!is_array($parent) || ($parent['mode'] & 0170000) !== 0040000
        || $parent['uid'] !== 0 || ($parent['mode'] & 0022) !== 0) return;
    foreach (@scandir($root) ?: [] as $entry) {
        if (strpos($entry, '.pmss-root-stage-') !== 0) continue;
        $path = $root.'/'.$entry;
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0177777) !== 0040700
            || $stat['uid'] !== 0 || $stat['mtime'] > time() - 3600) continue;
        $children = @scandir($path);
        if ($children === ['.', '..']) @rmdir($path);
    }
}

/** Build a publish step pinned to the current destination parent. */
function pmssRootStagePublishCommand(string $parent, string $home, string $stageFile, string $name, int $stageDevice): string
{
    if (!pmssPathAbsoluteStringIsSafe($parent, ['allowRoot' => false])
        || !pmssPathAbsoluteStringIsSafe($home, ['allowRoot' => false])
        || !pmssPathAbsoluteStringIsSafe($stageFile, ['allowRoot' => false])
        || $name === '' || $name === '.' || $name === '..' || strpos($name, '/') !== false) {
        throw new InvalidArgumentException('Invalid managed publish path');
    }
    $dir = escapeshellarg($parent);
    $homeArg = escapeshellarg($home);
    $source = escapeshellarg($stageFile);
    $leaf = escapeshellarg('./'.$name);
    return 'pin=$(stat -Lc %d:%i -- '.$dir.') || exit 1; '
        .'( cd -P -- '.$dir.' || exit 1; here=$(pwd -P); '
        .'case "$here" in '.$homeArg.'|'.$homeArg.'/*) ;; *) exit 1;; esac; '
        .'[ "$(stat -Lc %d:%i -- .)" = "$pin" ] || exit 1; '
        .'[ "$(stat -c %d -- .)" = '.escapeshellarg((string) $stageDevice).' ] || exit 1; '
        .'mv -T -- '.$source.' '.$leaf.' )';
}

/** Replace an authoritative or enforced file without writing inside the account tree as root. */
function pmssRootStageFileWrite(string $path, string $content, string $group, int $mode, bool $immutable = false): bool
{
    $context = pmssRootStageContext($path);
    if ($context === null || $mode < 0 || $mode > 0777 || !function_exists('posix_geteuid')
        || posix_geteuid() !== 0) return false;
    list($root, $home) = $context;
    $parent = dirname($path);
    $parentStat = @lstat($parent);
    $rootStat = @lstat($root);
    if (!pmssPathTargetIsSafe($parent, true)
        || !is_array($parentStat) || ($parentStat['mode'] & 0170000) !== 0040000
        || !is_array($rootStat) || $parentStat['dev'] !== $rootStat['dev']
        || !pmssPathWithinResolvedRoot($path, $home)) return false;
    static $sweptRoots = [];
    if (!isset($sweptRoots[$root])) {
        pmssRootStageSweep($root);
        $sweptRoots[$root] = true;
    }
    $stage = $root.'/.pmss-root-stage-'.bin2hex(random_bytes(12));
    if (!@mkdir($stage, 0700)) return false;
    $stageFile = $stage.'/file';
    try {
        $stageStat = @lstat($stage);
        if (!is_array($stageStat) || $stageStat['uid'] !== 0
            || ($stageStat['mode'] & 0177777) !== 0040700 || $stageStat['dev'] !== $parentStat['dev']) return false;
        $handle = @fopen($stageFile, 'x+b');
        if (!pmssStreamHandleIsOpen($handle)) return false;
        try {
            if (!pmssStreamWriteAll($handle, $content) || !@fflush($handle)) return false;
        } finally {
            @fclose($handle);
        }
        if (!@chown($stageFile, 'root') || !@chgrp($stageFile, $group)
            || !@chmod($stageFile, $mode)) return false;
        $published = @lstat($stageFile);
        if (!pmssUserFileStatIsSingleRegular($published) || $published['dev'] !== $parentStat['dev']) return false;
        $old = @lstat($path);
        $oldCleared = $immutable && is_array($old);
        if ($oldCleared && !pmssRootStageImmutableSet($path, $old, false)) return false;
        $command = pmssRootStagePublishCommand($parent, $home, $stageFile, basename($path), (int) $published['dev']);
        @exec($command.' 2>/dev/null', $output, $rc);
        if ($rc !== 0) {
            if ($oldCleared) pmssRootStageImmutableSet($path, $old, true);
            return false;
        }
        if ($immutable) return pmssRootStageImmutableSet($path, $published, true);
        return true;
    } finally {
        @unlink($stageFile);
        @rmdir($stage);
    }
}

/** Apply the immutable flag only when the published inode still matches. */
function pmssRootStageImmutableSet(string $path, array $expected, bool $enable): bool
{
    $context = pmssRootStageContext($path);
    if ($context === null || !isset($expected['dev'], $expected['ino'])) return false;
    list(, $home) = $context;
    $parent = dirname($path);
    $command = 'pin=$(stat -Lc %d:%i -- '.escapeshellarg($parent).') || exit 1; '
        .'( cd -P -- '.escapeshellarg($parent).' || exit 1; here=$(pwd -P); '
        .'case "$here" in '.escapeshellarg($home).'|'.escapeshellarg($home).'/*) ;; *) exit 1;; esac; '
        .'[ "$(stat -Lc %d:%i -- .)" = "$pin" ] || exit 1; '
        .'perl '.escapeshellarg(__DIR__.'/../pathSafety/immutableClear.pl').' '
        .($enable ? '--set ' : '--clear ').escapeshellarg((string) $expected['dev'].':'.$expected['ino'])
        .' '.escapeshellarg($home).' '.escapeshellarg('./'.basename($path)).' )';
    @exec($command.' 2>/dev/null', $output, $rc);
    return $rc === 0;
}
