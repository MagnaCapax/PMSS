<?php
/** Account password sync serialized against PMSS starts. */
/** Stop the account daemon before a credential write can be lost to Qt's next whole-file save. */
function pmssQbittorrentPasswordPrepare(string $user, string $home, array $ops = []): bool
{
    $stop = $ops['stop'] ?? static function (string $user): void { pmssTorrentClientNativeStop($user, 'qbittorrent', 9); };
    $pids = $ops['accountPids'] ?? 'pmssQbittorrentAccountPids';
    $pause = $ops['pause'] ?? static function (): void { usleep(100000); };
    $restore = $ops['restore'] ?? 'pmssQbittorrentRestore';
    $stop($user);
    for ($attempt = 0; $attempt < 50; $attempt++) {
        if ($pids() === []) return $restore($home);
        $pause();
    }
    $notice = $ops['notice'] ?? 'pmssQbittorrentNotice';
    $notice($home, 'qBittorrent password synchronization failed: another qbittorrent-nox process of this account is running (not started by PMSS).');
    return false;
}

/** Serialize credential writes against every PMSS start and version switch. */
function pmssQbittorrentPasswordApplyStopped(string $user, string $home, string $password, array $ops = []): bool
{
    $prepare = $ops['prepare'] ?? 'pmssQbittorrentPasswordPrepare';
    $write = $ops['password'] ?? static function (string $user, string $password): bool {
        $path = realpath(pmssQbittorrentConfigPath($user));
        return is_string($path) && pmssQbittorrentApplyPassword($user, $password, $path);
    };
    $notice = $ops['notice'] ?? 'pmssQbittorrentNotice';
    return pmssQbittorrentConfigLocked($home, static function () use ($user, $home, $password, $prepare, $write, $notice, $ops): bool {
        if (!$prepare($user, $home, $ops)) return false;
        if ($write($user, $password)) return true;
        $notice($home, 'qBittorrent password synchronization failed: the configuration could not be written.');
        return false;
    }, $ops + ['attempts' => 301, 'passwordSync' => true]);
}

/** Optional client sync cannot change the account-password command's exit status. */
function pmssQbittorrentPasswordSyncWarning(bool $updated, bool $installed, ?callable $warning = null): void
{
    if ($updated || !$installed) return;
    $warning = $warning ?? static function (string $message): void { fwrite(STDERR, $message); };
    $warning("qBittorrent password synchronization failed; see the account notice.\n");
}
