#!/usr/bin/env php
<?php
/**
 * Utility script: user Permissions.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
# Set user folder permissions

require_once __DIR__.'/../lib/userLifecycle.php';
require_once __DIR__.'/../lib/shell.php';
require_once __DIR__.'/../lib/pathSafety.php';
require_once __DIR__.'/../lib/user/userFilesystem.php';
require_once __DIR__.'/../lib/traffic/storage.php';

$usage = 'Usage: ./userPermissions.php USERNAME';
if (empty($argv[1]) ) die('need user name. ' . $usage . "\n");

['username' => $thisUser, 'homeDir' => $homeDir] = userFilesystem::requireCliUserHome((string) $argv[1], 'permissions', "Invalid username\n", "User does not exist\n");

$userIds = function_exists('pmssUserAccountLookup') ? pmssUserAccountLookup($thisUser) : null;
if (!is_array($userIds)) die("No such user\n");

function chmodPath(string $path, int $perm, bool $recursive = false): void
{
    // Hardening prompted by a security disclosure from Samanta.
    $target = pmssPathShellTarget($path);
    if ($target === null) {
        return;
    }

    if ($recursive) {
        $mode = sprintf('%04o', $perm);
        pmssRun(sprintf('find %s -not -type l -not -perm %s -exec chmod %s {} +', $target, $mode, $mode));
        return;
    }

    pmssRun(sprintf('chmod %s%o %s', $recursive ? '-R ' : '', $perm, $target));
}

function chownPath(string $path, string $owner, bool $recursive = false): void
{
    $target = pmssPathShellTarget($path);
    if ($target === null) {
        return;
    }

    if ($recursive) {
        $predicate = pmssFindOwnerMismatchPredicate($owner);
        if ($predicate !== '') {
            pmssRun(sprintf('find %s -not -type l %s -exec chown %s {} +', $target, $predicate, escapeshellarg($owner)));
            return;
        }
    }

    // Quote owner spec as a single argument; chown accepts quoted 'user.group'
    pmssRun(sprintf('chown %s%s %s', $recursive ? '-R ' : '', escapeshellarg($owner), $target));
}

function pmssFindOwnerMismatchPredicate(string $owner): string
{
    $delimiter = strpos($owner, ':') !== false ? ':' : (strpos($owner, '.') !== false ? '.' : '');
    if ($delimiter === '') {
        return '';
    }

    $parts = explode($delimiter, $owner, 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        return '';
    }

    return sprintf('\( -not -user %s -o -not -group %s \)', escapeshellarg($parts[0]), escapeshellarg($parts[1]));
}

// Safer traversal without relying on xargs delimiters; applies to each directory in place.
// Skip ~/.local entirely to avoid interfering with per-user application data (e.g. Docker overlays).
$homeOwner = @fileowner($homeDir);
$homeGroup = @filegroup($homeDir);
if ($homeOwner !== false && $homeGroup !== false &&
    ($homeOwner !== $userIds['uid'] || $homeGroup !== $userIds['gid'])) {
    fwrite(
        STDERR,
        sprintf(
            "[WARN] Fixing home directory ownership for %s (uid=%s gid=%s expected uid=%s gid=%s)\n",
            $homeDir,
            $homeOwner,
            $homeGroup,
            $userIds['uid'],
            $userIds['gid']
        )
    );
    chownPath($homeDir, $userIds['uid'].':'.$userIds['gid']);
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
pmssRun(sprintf(
    'find %s -path %s -prune -o -type d -not -perm 0750 -exec chmod 0750 {} +',
    escapeshellarg('/home/'.$thisUser),
    escapeshellarg("/home/{$thisUser}/.local")
));

// Existing-home residue of the #781 skel-mode regression: served ~/www content was
// copied carrying the exec bit. Strip exec from ~/www FILES (dirs are already normalised
// to 0750 by the walk above). Bounded to ~/www so large payload trees under ~/data are
// untouched; the explicit chmodItems below re-apply any file that legitimately keeps exec.
pmssRun(sprintf('find %s -type f -perm /0111 -exec chmod a-x {} +', escapeshellarg("/home/{$thisUser}/www")));

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
    pmssUserLog($thisUser, $binSpec[1]);
}

$chmodItems = [
    ["/home/{$thisUser}", 0770],
    ["/home/{$thisUser}/.bin", 0750, true],
    ["/home/{$thisUser}/bin", 0750, true],
    ["/home/{$thisUser}/.viminfo", 0640],
    ["/home/{$thisUser}/.quota", 0640],
    ["/home/{$thisUser}/.billingServiceId", 0640],
    ["/home/{$thisUser}/.billingId", 0640],
    ["/home/{$thisUser}/.billingClientId", 0640],
    ["/home/{$thisUser}/.profile", 0644],
    ["/home/{$thisUser}/.bash_history", 0640],
    ["/home/{$thisUser}/.bashrc", 0644],
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
    // ~/www is normalised above (dirs 0750 via the home walk, files exec-stripped);
    // a recursive 0750 here would re-add the exec bit to served data files (#781).
    ["/home/{$thisUser}/.*.php", 0750],
    ["/home/{$thisUser}/.lighttpd.conf", 0644],
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
    // Billing identity files: root-owned (user reads via group, cannot WRITE) so a user
    // cannot forge their own service/client id. Mirrors .quota. Writers are root
    // (recreateUser restore, mgmt-host backfill); order-time provisioning creates the file
    // before the first permission refresh, and the service id is stable (no user rewrite).
    // Root ownership closes the forgery at the source (GH #784).
    ["/home/{$thisUser}/.billingServiceId", "root:{$thisUser}"],
    ["/home/{$thisUser}/.billingId", "root:{$thisUser}"],
    ["/home/{$thisUser}/.billingClientId", "root:{$thisUser}"],
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
    // Managed shell configs: root-owned + world-readable (0644) so the user can still
    // SOURCE them but cannot edit — edits go in ~/.bashrc.user (sourced by .bashrc).
    // 0644 (not 0640) is required: a root-owned .bashrc at 0640 is unreadable by the
    // user (as "other") and would break login. Mirrors the .rtorrent.rc model.
    ["/home/{$thisUser}/.bashrc", "root:root"],
    ["/home/{$thisUser}/.profile", "root:root"],
    ["/home/{$thisUser}/.lighttpd.conf", "root:root"],
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
    "/home/{$thisUser}/.resourceData",
    "/home/{$thisUser}/.billingServiceId",
    "/home/{$thisUser}/.billingId",
    "/home/{$thisUser}/.billingClientId",
    $trafficPaths['normal'],
    $trafficPaths['local'],
    $trafficPaths['ingress'],
    $trafficPaths['ingressLocal'],
    "/home/{$thisUser}/.rtorrent.rc",
    "/home/{$thisUser}/www/rutorrent/conf/config.php",
    "/home/{$thisUser}/.bashrc",
    "/home/{$thisUser}/.profile",
    "/home/{$thisUser}/.lighttpd.conf",
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
