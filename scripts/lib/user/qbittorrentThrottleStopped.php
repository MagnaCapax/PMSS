<?php
/** Account-owned throttle state and stopped writes; mirrored for customer launches. */
require_once __DIR__.'/qbittorrentStoppedConfig.php';
require_once __DIR__.'/qbittorrentThrottle.php';

/** Return changed/unchanged, or null on refusal; no cap/record never reads daemon settings. */
function pmssQbittorrentThrottleAccount(string $home, ?int $throttle, bool $apply, array $ops = []): ?bool
{
    $state = $home.'/.config/pmss/torrentClients/qbittorrent.throttleApplied';
    $raw = pmssReadRegularFileContentsVerified($state, null, 32);
    $applied = pmssQbittorrentThrottleNumber($raw ?? '');
    $applied = $applied > 0 ? $applied : null;
    if (($throttle ?? 0) <= 0 && $applied === null && empty($ops['exact'])) return false;
    $result = null;
    $notice = $ops['notice'] ?? 'pmssQbittorrentNotice';
    $refuse = static function (string $message) use ($home, $notice): bool { $notice($home, $message); return false; };
    $work = static function () use ($home, $throttle, $apply, $ops, $refuse, $state, &$applied, &$result): bool {
        // Re-read ownership state under the same lock as every start and switch.
        $raw = pmssReadRegularFileContentsVerified($state, null, 32);
        $applied = pmssQbittorrentThrottleNumber($raw ?? '');
        $applied = $applied > 0 ? $applied : null;
        $pids = $ops['accountPids'] ?? 'pmssQbittorrentAccountPids';
        if ($apply) {
            $pause = $ops['pause'] ?? static function (): void { usleep(100000); };
            // Match Configure's 60-second TERM wait; Qt must finish saving before any read/write.
            for ($attempt = 0; $pids() !== []; $attempt++) {
                if ($attempt >= ($ops['stopAttempts'] ?? 600))
                    return $refuse('qBittorrent upload throttle deferred: an account process is still running.');
                $pause();
            }
        }
        $restore = $ops['restore'] ?? 'pmssQbittorrentRestore';
        if ($apply && empty($ops['restored']) && !$restore($home, $ops)) return false;
        $path = $home.'/.config/qBittorrent/qBittorrent.conf';
        if (!pmssQbittorrentFileSafe($path)) return $refuse(is_link($path)
            ? 'qBittorrent.conf is a link; PMSS will not write through it'
            : 'qBittorrent upload throttle deferred: the configuration path is unavailable.');
        $config = @file_get_contents($path);
        if (!is_string($config)) return $refuse('qBittorrent upload throttle deferred: the configuration could not be read.');
        $build = $ops['build'] ?? pmssQbittorrentBuildVersion($home, null, $ops);
        $updated = !empty($ops['exact']) ? pmssQbittorrentUploadThrottleConfig($config, $throttle, $build)
            : pmssQbittorrentThrottleContent($config, $throttle, $build, $applied);
        if (!is_string($updated)) return $refuse('qBittorrent upload throttle deferred: the configuration is malformed.');
        $differs = $updated !== $config;
        if (!$apply && $differs) { $result = true; return true; }
        if (!$apply) $updated = $config;
        $write = $ops['write'] ?? static function (string $path, string $content): bool {
            return pmssReplaceUserFilePreservingMetadata(realpath($path), $content, (int) @fileperms($path) & 0777);
        };
        if ($updated !== $config) {
            if ($pids() !== []) return $refuse('qBittorrent upload throttle deferred: an account process is still running.');
            // Record first: if publication fails, a later pass can safely retry/removal can check equality.
            if (($throttle ?? 0) > 0) {
                $recordWrite = $ops['recordWrite'] ?? static function ($path, $value): bool {
                    return pmssReplaceUserFilePreservingMetadata($path, $value, 0600);
                };
                if (!pmssQbittorrentFileSafe($state) || !$recordWrite($state, $throttle."\n"))
                    return $refuse('qBittorrent upload throttle failed: the applied-cap record could not be written.');
            }
            if (!$write($path, $updated)) return $refuse('qBittorrent upload throttle failed: the stopped configuration could not be written.');
        }
        // A customer edit after application is preserved; forgetting our record needs no daemon restart.
        if (($throttle ?? 0) <= 0 && (file_exists($state) || is_link($state))) {
            if (!pmssQbittorrentFileSafe($state) || !@unlink($state))
                return $refuse('qBittorrent upload throttle failed: the applied-cap record could not be cleared.');
        }
        // Remove only a throttle-deferred notice after successful convergence.
        $noticePath = $home.'/.config/pmss/torrentClients/qbittorrent.notice';
        $noticeText = pmssReadRegularFileContentsVerified($noticePath, null, 4096);
        if (is_string($noticeText) && strpos($noticeText, 'qBittorrent upload throttle ') === 0
            && pmssQbittorrentFileSafe($noticePath)) @unlink($noticePath);
        $result = $updated !== $config;
        return true;
    };
    $ok = !empty($ops['lockHeld']) ? $work() : pmssQbittorrentConfigLocked($home, $work, $ops);
    return $ok ? $result : null;
}
