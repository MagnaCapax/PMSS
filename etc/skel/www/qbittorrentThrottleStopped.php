<?php
/** Account-owned throttle state and stopped writes; mirrored for customer launches. */
require_once __DIR__.'/qbittorrentStoppedConfig.php';
require_once __DIR__.'/qbittorrentThrottle.php';
require_once __DIR__.'/configureFileWrite.php';

/** Remove only a throttle-deferred notice after successful convergence. */
function pmssQbittorrentFrontendThrottleNoticeClear(string $home): void
{
    $path = $home.'/.config/pmss/torrentClients/qbittorrent.notice';
    $text = \PMSS\Customer\pmssReadRegularFileContentsVerified($path, null, 4096);
    if (is_string($text) && strpos($text, 'qBittorrent upload throttle ') === 0
        && pmssQbittorrentFrontendFileSafe($path)) @unlink($path);
}

/** Apply positive caps only; removal belongs to the root watchdog's account writer. */
function pmssQbittorrentFrontendThrottleAccount(string $home, ?int $throttle, bool $apply, array $ops = []): ?bool
{
    if (($throttle ?? 0) <= 0) return false;
    $state = $home.'/.config/pmss/torrentClients/qbittorrent.throttleApplied';
    $result = null;
    $notice = $ops['notice'] ?? 'pmssQbittorrentFrontendNotice';
    $refuse = static function (string $message) use ($home, $notice): bool { $notice($home, $message); return false; };
    $work = static function () use ($home, $throttle, $apply, $ops, $refuse, $state, &$result): bool {
        $pids = $ops['accountPids'] ?? 'pmssQbittorrentFrontendAccountPids';
        if ($apply) {
            $pause = $ops['pause'] ?? static function (): void { usleep(100000); };
            // Match Configure's 60-second TERM wait; Qt must finish saving before any read/write.
            for ($attempt = 0; $pids() !== []; $attempt++) {
                if ($attempt >= ($ops['stopAttempts'] ?? 600))
                    return $refuse('qBittorrent upload throttle deferred: an account process is still running.');
                $pause();
            }
        }
        $restore = $ops['restore'] ?? 'pmssQbittorrentFrontendRestore';
        if ($apply && empty($ops['restored']) && !$restore($home, $ops)) return false;
        $path = $home.'/.config/qBittorrent/qBittorrent.conf';
        if (!pmssQbittorrentFrontendFileSafe($path)) return $refuse(is_link($path)
            ? 'qBittorrent.conf is a link; PMSS will not write through it'
            : 'qBittorrent upload throttle deferred: the configuration path is unavailable.');
        $config = @file_get_contents($path);
        if (!is_string($config)) return $refuse('qBittorrent upload throttle deferred: the configuration could not be read.');
        $build = $ops['build'] ?? pmssQbittorrentFrontendBuildVersion($home, null, $ops);
        $differs = pmssQbittorrentFrontendThrottleDiffers($config, $throttle, $build);
        if (!$apply && $differs) { $result = true; return true; }
        $updated = $apply ? pmssQbittorrentFrontendThrottleContent($config, $throttle, $build) : $config;
        $write = $ops['write'] ?? static function (string $path, string $content): bool {
            return pmssConfigureFileWrite($path, $content);
        };
        if ($updated !== $config) {
            if ($pids() !== []) return $refuse('qBittorrent upload throttle deferred: an account process is still running.');
            // Record first: if publication fails, a later pass can safely retry/removal can check equality.
            if (($throttle ?? 0) > 0) {
                $recordWrite = $ops['recordWrite'] ?? static function ($path, $value): bool {
                    return pmssConfigureFileWrite($path, $value);
                };
                if (!pmssQbittorrentFrontendFileSafe($state) || !$recordWrite($state, $throttle."\n"))
                    return $refuse('qBittorrent upload throttle failed: the applied-cap record could not be written.');
            }
            if (!$write($path, $updated)) return $refuse('qBittorrent upload throttle failed: the stopped configuration could not be written.');
        }
        pmssQbittorrentFrontendThrottleNoticeClear($home);
        $result = $updated !== $config;
        return true;
    };
    $ok = !empty($ops['lockHeld']) ? $work() : pmssQbittorrentFrontendConfigLocked($home, $work, $ops);
    return $ok ? $result : null;
}
