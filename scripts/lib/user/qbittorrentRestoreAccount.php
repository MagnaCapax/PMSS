<?php
/** Load operator code before dropping privileges for account-owned restoration. */
require_once __DIR__.'/qbittorrentStoppedConfig.php';
require_once dirname(__DIR__, 3).'/etc/skel/www/configureTorrentClients.php';
require_once dirname(__DIR__, 3).'/etc/skel/www/qbittorrentRefusal.php';

/** Run all fallback promotion, reads and writes as the real account (ADR 0121). */
function pmssQbittorrentRestoreAsUser(string $user, string $home, bool $observe = false, bool $release = false): bool
{
    $account = @posix_getpwnam($user);
    if (!is_array($account) || $account['uid'] === 0 || rtrim($account['dir'], '/') !== rtrim($home, '/')) return false;
    if (posix_geteuid() === $account['uid']) return pmssQbittorrentAccountWatchdog($home, $observe, ['release' => $release]);
    if (posix_geteuid() !== 0) return false;
    // No code in the account tree is loaded by root or after this privilege drop.
    $code = 'require_once '.var_export(__FILE__, true).';'.<<<'PHP'
$account = posix_getpwnam($argv[1]);
if (!is_array($account) || $account['uid'] === 0
    || !posix_initgroups($argv[1], (int) $account['gid'])
    || !posix_setgid((int) $account['gid']) || !posix_setuid((int) $account['uid'])) exit(1);
$ok = pmssQbittorrentAccountWatchdog($argv[2], $argv[3] === 'observe', ['release' => $argv[3] === 'release']);
exit($ok ? 0 : 1);
PHP;
    $output = [];
    return pmssCredentialProcessRun(pmssBuildCommand(PHP_BINARY, ['-r', $code, $user, $home, $observe ? 'observe' : ($release ? 'release' : 'restore')]), null, $output, false, 30) === 0;
}

/** This code runs as the account; root never reads or writes its notice/config paths. */
function pmssQbittorrentAccountWatchdog(string $home, bool $observe, array $ops = []): bool
{
    if ($observe) {
        $check = $ops['check'] ?? 'pmssQbittorrentFrontendSocketWait';
        if (!$check($home, $ops + ['attempts' => 1, 'pause' => static function (): void {}])) return false;
        pmssQbittorrentFrontendBuildRecord($home, pmssQbittorrentFrontendBuildVersion($home));
        return true;
    }
    if (!empty($ops['release'])) return pmssQbittorrentFrontendConfigLocked($home, static function () use ($home, $ops): bool {
        return pmssQbittorrentFrontendRefusalReady($home, $ops);
    });
    $restore = $ops['restore'] ?? 'pmssQbittorrentRestore';
    return pmssQbittorrentConfigLocked($home, static function () use ($home, $restore): bool {
        return $restore($home);
    });
}
