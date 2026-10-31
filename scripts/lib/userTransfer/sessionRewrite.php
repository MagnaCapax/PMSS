<?php
/**
 * rTorrent session rewrite helpers for user transfers.
 *
 * @license GPL-3.0-only
 */
require_once __DIR__.'/sessionBencode.php';

/**
 * Rewrite /home/<remote>/ path strings in local rTorrent session files.
 *
 * This is best-effort and fail-soft: malformed files are skipped and logged,
 * while valid files continue processing.
 */
function pmssUserTransferRewriteRtorrentSessionPaths(array $cfg, string $home): void
{
    $remoteUser = (string) ($cfg['remoteUser'] ?? '');
    $localUser = (string) ($cfg['localUser'] ?? '');
    if ($remoteUser === '' || $localUser === '' || $remoteUser === $localUser) {
        return;
    }
    $sessionDir = rtrim($home, '/').'/session';
    if (!is_dir($sessionDir)) {
        logMessage('[INFO] Skipping rTorrent session rewrite (session directory missing)');
        return;
    }
    clearstatcache(true, $sessionDir);
    if (!pmssPathTargetIsSafe($sessionDir, true) || !pmssUserTransferIsPathWithinHome($sessionDir, $home)) {
        logMessage('[WARN] Skipping rTorrent session rewrite (session path escapes user home)');
        return;
    }
    $sessionFiles = glob($sessionDir.'/*.rtorrent');
    if (!$sessionFiles) {
        logMessage('[INFO] No rTorrent session files found for path rewrite');
        return;
    }
    $rewrittenFiles = 0;
    $rewrittenPaths = 0;
    foreach ($sessionFiles as $sessionFile) {
        clearstatcache(true, $sessionFile);
        if (!pmssPathTargetIsSafe($sessionFile, false, true)
            || !pmssUserTransferIsPathWithinHome($sessionFile, $home)) {
            logMessage('[WARN] Skipping unsafe rTorrent session file: '.$sessionFile);
            continue;
        }
        $fileReplacements = 0;
        $ok = pmssInodeSafeRewriteRegularFile($home, $sessionFile,
            static function (string $raw) use ($remoteUser, $localUser, &$fileReplacements): ?string {
                return pmssUserTransferRewriteBencodedHomePaths($raw, $remoteUser, $localUser, $fileReplacements);
            });
        if (!$ok) {
            logMessage('[WARN] Skipping unsafe, unreadable or malformed rTorrent session file: '.$sessionFile);
            continue;
        }
        if ($fileReplacements < 1) continue;
        $rewrittenFiles++;
        $rewrittenPaths += $fileReplacements;
    }
    logMessage($rewrittenPaths > 0
        ? sprintf('[OK] Rewrote %d path reference(s) across %d rTorrent session file(s)', $rewrittenPaths, $rewrittenFiles)
        : '[INFO] rTorrent session rewrite found no /home path references to update');
}
