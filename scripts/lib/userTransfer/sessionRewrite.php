<?php
/**
 * rTorrent session rewrite helpers for user transfers.
 *
 * @license GPL-3.0-only
 */
require_once __DIR__.'/localUserSafety.php';
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
    if (!pmssUserTransferIsPathWithinHome($sessionDir, $home)) {
        logMessage('[WARN] Skipping rTorrent session rewrite (session path escapes user home)');
        return;
    }
    $sessionFiles = glob($sessionDir.'/*.rtorrent');
    if (!is_array($sessionFiles) || $sessionFiles === []) {
        logMessage('[INFO] No rTorrent session files found for path rewrite');
        return;
    }
    $rewrittenFiles = 0;
    $rewrittenPaths = 0;
    foreach ($sessionFiles as $sessionFile) {
        if (!is_file($sessionFile) || !pmssUserTransferIsPathWithinHome($sessionFile, $home)) {
            logMessage('[WARN] Skipping unsafe rTorrent session file: '.$sessionFile);
            continue;
        }
        $raw = @file_get_contents($sessionFile);
        if (!is_string($raw)) {
            logMessage('[WARN] Unable to read rTorrent session file: '.$sessionFile);
            continue;
        }
        $fileReplacements = 0;
        $rewritten = pmssUserTransferRewriteBencodedHomePaths($raw, $remoteUser, $localUser, $fileReplacements);
        if ($rewritten === null) {
            logMessage('[WARN] Skipping malformed rTorrent session file: '.$sessionFile);
            continue;
        }
        if ($fileReplacements < 1) {
            continue;
        }
        if (@file_put_contents($sessionFile, $rewritten) === false) {
            logMessage('[WARN] Failed to rewrite rTorrent session file: '.$sessionFile);
            continue;
        }
        $rewrittenFiles++;
        $rewrittenPaths += $fileReplacements;
    }
    if ($rewrittenPaths > 0) {
        logMessage(sprintf('[OK] Rewrote %d path reference(s) across %d rTorrent session file(s)', $rewrittenPaths, $rewrittenFiles));
        return;
    }
    logMessage('[INFO] rTorrent session rewrite found no /home path references to update');
}
/**
 * Rewrite bencoded string values containing /home/<oldUser>/ paths.
 *
 * Returns null when the payload is malformed and cannot be safely rewritten.
 */
function pmssUserTransferRewriteBencodedHomePaths(string $payload, string $oldUser, string $newUser, int &$replacementCount = 0): ?string
{
    $replacementCount = 0;
    $needle = '/home/'.$oldUser.'/';
    $replacement = '/home/'.$newUser.'/';
    if ($needle === $replacement) {
        return $payload;
    }
    $offset = 0;
    $payloadLength = strlen($payload);
    $rewritten = '';
    while ($offset < $payloadLength) {
        $token = $payload[$offset];
        if ($token >= '0' && $token <= '9') {
            $digitsStart = $offset;
            while ($offset < $payloadLength) {
                $digit = $payload[$offset];
                if ($digit < '0' || $digit > '9') {
                    break;
                }
                $offset++;
            }
            if ($offset >= $payloadLength || $payload[$offset] !== ':') {
                return null;
            }
            $lengthToken = substr($payload, $digitsStart, $offset - $digitsStart);
            if ($lengthToken === '' || strlen($lengthToken) > 9) {
                return null;
            }
            $stringLength = (int) $lengthToken;
            if ($stringLength < 0) {
                return null;
            }
            $offset++;
            if ($offset + $stringLength > $payloadLength) {
                return null;
            }
            $value = substr($payload, $offset, $stringLength);
            $offset += $stringLength;
            $replaced = str_replace($needle, $replacement, $value, $hits);
            if ($hits > 0) {
                $replacementCount += $hits;
                $value = $replaced;
            }
            $rewritten .= strlen($value).':'.$value;
            continue;
        }
        if ($token === 'i') {
            $intEnd = strpos($payload, 'e', $offset + 1);
            if ($intEnd === false) {
                return null;
            }
            $rewritten .= substr($payload, $offset, $intEnd - $offset + 1);
            $offset = $intEnd + 1;
            continue;
        }
        if ($token === 'l' || $token === 'd' || $token === 'e') {
            $rewritten .= $token;
            $offset++;
            continue;
        }
        return null;
    }
    return $rewritten;
}
