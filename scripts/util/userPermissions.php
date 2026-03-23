#!/usr/bin/env php
<?php
/**
 * Utility script: user Permissions.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
# Set user folder permissions

if (is_file($pmssUserLogPath = __DIR__.'/../lib/user/log.php')) { require_once $pmssUserLogPath; }
if (is_file($pmssUserLifecyclePath = __DIR__.'/../lib/userLifecycle.php')) { require_once $pmssUserLifecyclePath; }
if (is_file($pmssShellPath = __DIR__.'/../lib/shell.php')) { require_once $pmssShellPath; }
require_once __DIR__.'/../lib/traffic/storage.php';

$usage = 'Usage: ./userPermissions.php USERNAME';
if (empty($argv[1]) ) die('need user name. ' . $usage . "\n");

$userRaw = (string) $argv[1];
$thisUser = function_exists('pmssUsernameNormalizeIfValid')
    ? pmssUsernameNormalizeIfValid($userRaw)
    : null;
if ($thisUser === null) {
    die("Invalid username\n");
}
if (!is_dir("/home/{$thisUser}")) die("User does not exist\n");

$userIds = function_exists('pmssUserAccountLookup') ? pmssUserAccountLookup($thisUser) : null;
if (!is_array($userIds)) die("No such user\n");

function chmodPath(string $path, int $perm, bool $recursive = false): void
{
    $target = pmssPathShellTarget($path);
    if ($target === null) {
        return;
    }

    pmssRun(sprintf('chmod %s%o %s', $recursive ? '-R ' : '', $perm, $target));
}

function pmssPathShellTarget(string $path): ?string
{
    $hasGlob = strpbrk($path, '*?[]') !== false;

    if ($hasGlob) {
        $matches = glob($path);
        if ($matches === false || $matches === []) {
            return null;
        }
        return $path;
    }

    return file_exists($path) ? escapeshellarg($path) : null;
}

function chownPath(string $path, string $owner, bool $recursive = false): void
{
    $target = pmssPathShellTarget($path);
    if ($target === null) {
        return;
    }

    // Quote owner spec as a single argument; chown accepts quoted 'user.group'
    pmssRun(sprintf('chown %s%s %s', $recursive ? '-R ' : '', escapeshellarg($owner), $target));
}

// Safer traversal without relying on xargs delimiters; applies to each directory in place.
// Skip ~/.local entirely to avoid interfering with per-user application data (e.g. Docker overlays).
$homeOwner = @fileowner("/home/{$thisUser}");
$homeGroup = @filegroup("/home/{$thisUser}");
if ($homeOwner !== false && $homeGroup !== false &&
    ($homeOwner !== $userIds['uid'] || $homeGroup !== $userIds['gid'])) {
    fwrite(
        STDERR,
        sprintf(
            "[WARN] Fixing home directory ownership for %s (uid=%s gid=%s expected uid=%s gid=%s)\n",
            "/home/{$thisUser}",
            $homeOwner,
            $homeGroup,
            $userIds['uid'],
            $userIds['gid']
        )
    );
    chownPath("/home/{$thisUser}", $userIds['uid'].':'.$userIds['gid']);
    if (function_exists('pmssUserLog')) {
        pmssUserLog(
            $thisUser,
            sprintf(
                'userPermissions: fixed home ownership (uid=%s gid=%s, was uid=%s gid=%s)',
                $userIds['uid'],
                $userIds['gid'],
                $homeOwner,
                $homeGroup
            )
        );
    }
}
pmssRun(sprintf(
    'find %s -path %s -prune -o -type d -exec chmod 750 {} +',
    escapeshellarg('/home/'.$thisUser),
    escapeshellarg("/home/{$thisUser}/.local")
));

// Ensure ~/.bin and ~/bin exist with safe permissions and ownership.
foreach ([
    ["/home/{$thisUser}/.bin", 'userPermissions: created ~/.bin with safe ownership'],
    ["/home/{$thisUser}/bin", 'userPermissions: created ~/bin with safe ownership'],
] as $binSpec) {
    $binDir = $binSpec[0];
    if (is_dir($binDir)) {
        continue;
    }
    pmssRun(sprintf('mkdir -p %s', escapeshellarg($binDir)));
    chownPath($binDir, "{$thisUser}:{$thisUser}");
    chmodPath($binDir, 0750, true);
    if (function_exists('pmssUserLog')) {
        pmssUserLog($thisUser, $binSpec[1]);
    }
}

$chmodItems = [
    ["/home/{$thisUser}", 0770],
    ["/home/{$thisUser}/.bin", 0750, true],
    ["/home/{$thisUser}/bin", 0750, true],
    ["/home/{$thisUser}/.viminfo", 0640],
    ["/home/{$thisUser}/.quota", 0640],
    ["/home/{$thisUser}/.profile", 0640],
    ["/home/{$thisUser}/.bash_history", 0640],
    ["/home/{$thisUser}/.bashrc", 0640],
    ["/home/{$thisUser}/.bashrc.user", 0640],
    ["/home/{$thisUser}/.tmp", 0770],
    ["/home/{$thisUser}/.config", 0770, true],
    ["/home/{$thisUser}/.trafficData", 0640],
    ["/home/{$thisUser}/.trafficDataIngress", 0640],
    ["/home/{$thisUser}/.rtorrent.rc", 0644],
    ["/home/{$thisUser}/watch", 0750, true],
    ["/home/{$thisUser}/session", 0750, true],
    // The directory walk above already normalises nested data directories.
    // Avoid `chmod -R` on payload files so large homes do not time out here.
    ["/home/{$thisUser}/data", 0750],
    ["/home/{$thisUser}/www", 0750, true],
    ["/home/{$thisUser}/.*.php", 0750],
    ["/home/{$thisUser}/.lighttpd", 0775],
    ["/home/{$thisUser}/.lighttpd/.htpasswd", 0754],
    ["/home/{$thisUser}/.lighttpd/compress", 0770],
    ["/home/{$thisUser}/.lighttpd/upload", 0770],
    ["/home/{$thisUser}/www/rutorrent/conf/config.php", 0754],
    ["/home/{$thisUser}/.irssi", 0750],
    ["/home/{$thisUser}/.sync", 0750],
];

$trafficPaths = pmssTrafficDataPaths($thisUser);

$chownItems = [
    ["/home/{$thisUser}/.lighttpd/.htpasswd", "{$thisUser}:{$thisUser}"],
    ["/home/{$thisUser}/.lighttpd/", "{$thisUser}:{$thisUser}", true],
    // NOTE: Avoid blanket chown -R on the whole home; exclude known root-owned files/dirs first.
    // The remaining tree is handled by a targeted find below.
    ["/home/{$thisUser}/.quota", "root:{$thisUser}"],
    [$trafficPaths['normal'], "root:{$thisUser}"],
    [$trafficPaths['local'], "root:{$thisUser}"],
    [$trafficPaths['ingress'], "root:{$thisUser}"],
    [$trafficPaths['ingressLocal'], "root:{$thisUser}"],
    ["/home/{$thisUser}/data", "{$thisUser}:{$thisUser}"],
    ["/home/{$thisUser}/www/rutorrent/share/users/{$thisUser}/settings", "{$thisUser}:{$thisUser}"],
    ["/home/{$thisUser}/www/rutorrent/share/users/{$thisUser}/settings/retrackers.dat", "{$thisUser}:{$thisUser}"],
    ["/home/{$thisUser}/www/rutorrent/share/users/{$thisUser}", "{$thisUser}:{$thisUser}"],
    ["/home/{$thisUser}/www/rutorrent/share/users/{$thisUser}/torrents", "{$thisUser}:{$thisUser}"],
    ["/home/{$thisUser}/.rtorrent.rc", "root:root"],
    ["/home/{$thisUser}/www/rutorrent/conf/config.php", "root:root"],
];

$trafficFiles = array_values($trafficPaths);

// Ensure traffic files are mutable while ownership and permissions are repaired.
foreach ($trafficFiles as $trafficFile) {
    pmssTrafficSetImmutable($trafficFile, false);
}

foreach ($chmodItems as $item) {
    $path = $item[0];
    $perm = $item[1];
    $recursive = isset($item[2]) ? (bool)$item[2] : false;
    chmodPath($path, $perm, $recursive);
}

// Targeted chown of the home tree excluding known root-owned paths, app-managed
// trees, and bulk payload data. Healthy homes should not pay a blanket chown
// cost when uid/gid ownership is already correct.
$excludes = [
    "/home/{$thisUser}/.quota",
    $trafficPaths['normal'],
    $trafficPaths['local'],
    $trafficPaths['ingress'],
    $trafficPaths['ingressLocal'],
    "/home/{$thisUser}/.rtorrent.rc",
    "/home/{$thisUser}/www/rutorrent/conf/config.php",
];
$findParts = [sprintf('find %s -mindepth 1', escapeshellarg("/home/{$thisUser}"))];
// Prune ~/.local subtree to avoid noisy chown failures on application-managed trees (e.g. Docker).
$findParts[] = '-path '.escapeshellarg("/home/{$thisUser}/.local").' -prune -o';
// Bulk data files are user payload and typically already owned correctly; avoid
// walking that whole subtree during routine permission refreshes.
$findParts[] = '-path '.escapeshellarg("/home/{$thisUser}/data").' -prune -o';
foreach ($excludes as $ex) {
    $findParts[] = '-not -path '.escapeshellarg($ex);
}
// Skip symbolic links so broken symlinks (e.g. ~/www/watch) do not cause chown
// dereference errors or non-zero rc noise in logs.
$findParts[] = '-not -type l';
$findParts[] = '\\(';
$findParts[] = '-not -uid '.(string) $userIds['uid'];
$findParts[] = '-o';
$findParts[] = '-not -gid '.(string) $userIds['gid'];
$findParts[] = '\\)';
$findParts[] = '-exec chown';
$findParts[] = escapeshellarg($userIds['uid'].':'.$userIds['gid']);
$findParts[] = '{}';
$findParts[] = '+';
pmssRun(implode(' ', $findParts));

foreach ($chownItems as $item) {
    $path = $item[0];
    $owner = $item[1];
    $recursive = isset($item[2]) ? (bool)$item[2] : false;
    chownPath($path, $owner, $recursive);
}

foreach ($trafficFiles as $trafficFile) {
    pmssTrafficSetImmutable($trafficFile, true);
}

if (file_exists("/home/{$thisUser}/.ssh")) {
    chmodPath("/home/{$thisUser}/.ssh", 0750);
}
