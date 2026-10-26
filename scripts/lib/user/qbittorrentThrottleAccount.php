<?php
/** Account-identity throttle reads and stopped writes share the existing switch lock. */
require_once __DIR__.'/qbittorrentStoppedConfig.php';

/** Return changed/unchanged, or null on refusal; preload trusted code before dropping UID. */
function pmssQbittorrentThrottleAsUser(string $user, string $home, ?int $throttle, bool $apply, bool $exact = false): ?bool
{
    $account = pmssAccountPathIdentity($user, $home);
    if (!is_array($account) || @fileowner($home) !== $account['uid'] || !pmssPathTargetIsSafe($home, true)) return null;
    if (posix_geteuid() === $account['uid']) return pmssQbittorrentThrottleAccount($home, $throttle, $apply, ['exact' => $exact]);
    if (posix_geteuid() !== 0 || $account['uid'] === 0 || rtrim($account['dir'], '/') !== rtrim($home, '/')) return null;
    $code = 'require_once '.var_export(__FILE__, true).';'.<<<'PHP'
$account = posix_getpwnam($argv[1]);
if (!is_array($account) || $account['uid'] === 0
    || !posix_initgroups($argv[1], (int) $account['gid'])
    || !posix_setgid((int) $account['gid']) || !posix_setuid((int) $account['uid'])) exit(1);
$result = pmssQbittorrentThrottleAccount($argv[2], $argv[3] === '' ? null : (int) $argv[3], $argv[4] === 'apply', ['exact' => $argv[5] === '1']);
exit($result === null ? 1 : ($result ? 2 : 0));
PHP;
    $output = [];
    $rc = pmssCredentialProcessRun(pmssBuildCommand(PHP_BINARY, ['-r', $code, $user, $home,
        $throttle === null ? '' : (string) $throttle, $apply ? 'apply' : 'check', $exact ? '1' : '0']), null, $output, false, $apply ? 75 : 5);
    return $rc === 0 ? false : ($rc === 2 ? true : null);
}


require_once __DIR__.'/qbittorrentThrottleStopped.php';
