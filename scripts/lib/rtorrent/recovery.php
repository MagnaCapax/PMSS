<?php
/**
 * rTorrent per-user recovery file helpers.
 *
 * Owns destructive user-tree recovery targets so process control code does not
 * also carry session-reset and custom-config quarantine policy.
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 * @license   Proprietary
 */

require_once __DIR__.'/../pathSafety.php';
require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/legacyDirectives.php';

/**
 * Validate a destructive per-user rTorrent target before moving or creating it.
 */
function rtorrentProcessUserTargetPathIsSafe(
    string $home,
    string $user,
    string $relativePath,
    bool $directoryTarget,
    bool $requireUserTailInTest = false
): bool {
    $home = rtrim($home, '/');
    $testMode = pmssTestModeEnabled();
    if ((!$testMode && !pmssValidateUsername($user))
        || $home === ''
        || $home[0] !== '/'
        || strpos($home, "\0") !== false
        || !is_dir($home)
        || is_link($home)
        || !pmssPathRelativeStringIsSafe($relativePath, ['allowControlChars' => true])
    ) {
        return false;
    }

    if (!$testMode && $home !== '/home/'.$user) {
        return false;
    }
    if ($testMode && $requireUserTailInTest && substr($home, -strlen('/'.$user)) !== '/'.$user) {
        return false;
    }

    $target = $home.'/'.$relativePath;
    return strpos($target, $home.'/') === 0
        && pmssPathTargetIsSafe($target, $directoryTarget, false, false);
}

/**
 * Reset a user's rTorrent session directory conservatively.
 */
function rtorrentProcessResetSessionDirectory(string $home, string $user, callable $logFn): bool
{
    $home = rtrim($home, '/');
    $sessionDir = $home.'/session';
    $expected = '/home/'.$user.'/session';
    $testMode = pmssTestModeEnabled();

    if ((!$testMode && ($sessionDir !== $expected || strpos($sessionDir, '/home/') !== 0))
        || ($testMode && substr($sessionDir, -strlen('/'.$user.'/session')) !== '/'.$user.'/session')
    ) {
        $logFn("Refusing to reset unexpected session directory: {$sessionDir}", true);
        return false;
    }
    if (is_link($sessionDir)) {
        $logFn("Refusing to reset symlinked session directory: {$sessionDir}", true);
        return false;
    }
    if (!rtorrentProcessUserTargetPathIsSafe($home, $user, 'session', true, true)) {
        $logFn("Refusing to reset unsafe session directory: {$sessionDir}", true);
        return false;
    }

    if (is_dir($sessionDir)) {
        $backup = $sessionDir.'.broken-'.date('Ymd_His').'-'.(int) getmypid();
        if (!@rename($sessionDir, $backup)) {
            $logFn("Failed to quarantine broken session directory: {$sessionDir}", true);
            return false;
        }
        $logFn("Quarantined broken session directory: {$sessionDir} -> {$backup}", true);
    }

    if (!pmssDirEnsureExists($sessionDir, 0755)) {
        $logFn("Failed to recreate session directory: {$sessionDir}", true);
        return false;
    }

    @chown($sessionDir, $user);
    if (function_exists('posix_getpwnam')) {
        $pw = @posix_getpwnam($user);
        if (is_array($pw) && isset($pw['gid'])) {
            @chgrp($sessionDir, (int) $pw['gid']);
        }
    }

    return true;
}

/**
 * Find legacy custom-config directives already migrated out of PMSS templates.
 *
 * @return string[] Legacy directive labels found in the config.
 */
function rtorrentCustomConfigFindLegacyDirectives(string $content): array
{
    if ($content === '') {
        return [];
    }

    $matches = [];
    foreach (pmssRtorrentLegacyDirectiveNames() as $label) {
        if (preg_match('/^\s*'.preg_quote($label, '/').'\s*=/m', $content) === 1) {
            $matches[] = $label;
        }
    }

    return $matches;
}

/**
 * Quarantine a user's custom rTorrent config file for support review.
 */
function rtorrentCustomConfigQuarantine(string $home, string $user, callable $logFn): ?string
{
    $home = rtrim($home, '/');
    $src = $home.'/.rtorrent.rc.custom';
    if (!rtorrentProcessUserTargetPathIsSafe($home, $user, '.rtorrent.rc.custom', false)) {
        $logFn("Refusing to quarantine unsafe custom rTorrent config: {$src}", true);
        return null;
    }
    if (!is_file($src) || is_link($src)) {
        return null;
    }

    // Best-effort ownership guard: only allow root-owned or user-owned files.
    if (function_exists('posix_getpwnam')) {
        $pw = @posix_getpwnam($user);
        $uid = (is_array($pw) && isset($pw['uid'])) ? (int) $pw['uid'] : null;
        $owner = @fileowner($src);
        if ($owner !== false && $uid !== null && (int) $owner !== 0 && (int) $owner !== $uid) {
            $logFn("Refusing to quarantine {$src}: unexpected owner uid={$owner}", true);
            return null;
        }
    }

    $content = @file_get_contents($src);
    if (is_string($content)) {
        $legacyDirectives = rtorrentCustomConfigFindLegacyDirectives($content);
        if ($legacyDirectives !== []) {
            $logFn('Custom rTorrent config still uses legacy PMSS-migrated directives: '.implode(', ', $legacyDirectives), true);
        }
    }

    $dst = $src.'.broken-'.date('Ymd_His').'-'.(int) getmypid();
    if (@rename($src, $dst)) {
        $logFn("Quarantined broken custom rTorrent config: {$src} -> {$dst}", true);
        return $dst;
    }
    $logFn("Failed to quarantine custom rTorrent config: {$src}", true);
    return null;
}
