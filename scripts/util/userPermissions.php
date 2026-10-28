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
require_once __DIR__.'/../lib/user/permissionsCommands.php';
require_once __DIR__.'/../lib/user/permissionsApply.php';
require_once __DIR__.'/../lib/user/rootArtifactPermissions.php';
require_once __DIR__.'/../lib/pathSafety.php';
require_once __DIR__.'/../lib/user/userFilesystem.php';
require_once __DIR__.'/../lib/user/subordinateIds.php';
require_once __DIR__.'/../lib/user/directories.php';
require_once __DIR__.'/../lib/lighttpd/accountPath.php';
require_once __DIR__.'/../lib/traffic/storage.php';

$usage = 'Usage: ./userPermissions.php USERNAME';
if (empty($argv[1]) ) die('need user name. ' . $usage . "\n");

['username' => $thisUser, 'homeDir' => $homeDir] = userFilesystem::requireCliUserHome((string) $argv[1], 'permissions', "Invalid username\n", "User does not exist\n");

$userIds = function_exists('pmssUserAccountLookup') ? pmssUserAccountLookup($thisUser) : null;
if (!is_array($userIds)) die("No such user\n");

// Each repair acts on the object it checked (scripts/lib/user/permissionsApply.php):
// modes change as the account or through an opened root-owned file, and owners change
// with chown -h run by -execdir from a find that starts at the home.
$accountUid = (int) $userIds['uid'];

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
    pmssUserPermissionsOwnerApply($homeDir, $homeDir, $userIds['uid'].':'.$userIds['gid']);
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

// Ensure ~/.bin and ~/bin exist with safe permissions and ownership.
foreach ([
    ["/home/{$thisUser}/.bin", 'userPermissions: created ~/.bin with safe ownership'],
    ["/home/{$thisUser}/bin", 'userPermissions: created ~/bin with safe ownership'],
] as $binSpec) {
    $binDir = $binSpec[0];
    if (is_dir($binDir)) {
        continue;
    }
    $created = pmssAccountPathRun($thisUser, $homeDir, [$binDir], 'mkdir -m 750 -- '.escapeshellarg($binDir));
    if ($created || pmssEnsureUserHomeDir($thisUser, $homeDir, basename($binDir), 0750)) {
        pmssUserLog($thisUser, $binSpec[1]);
    }
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
    ["/home/{$thisUser}/.notifyEmail", 0640],
    ["/home/{$thisUser}/.profile", 0644],
    ["/home/{$thisUser}/.bash_history", 0640],
    ["/home/{$thisUser}/.bashrc", 0644],
    ["/home/{$thisUser}/.bashrc.user", 0640],
    // Heal non-executable skeleton files copied with mode 0755 before #1019.
    ["/home/{$thisUser}/radarr-sonarr.txt", 0644],
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
    // Include-only; the glob above is for the wrappers that are executed.
    ["/home/{$thisUser}/.scriptsInc.php", 0640],
    ["/home/{$thisUser}/.lighttpd.conf", 0644],
    ["/home/{$thisUser}/.lighttpd", 0775],
    ["/home/{$thisUser}/.lighttpd/php.ini", 0640],
    // Matches the htpasswd writer; every reader runs as the account.
    ["/home/{$thisUser}/.lighttpd/.htpasswd", 0640],
    ["/home/{$thisUser}/.lighttpd/compress", 0770],
    ["/home/{$thisUser}/.lighttpd/upload", 0770],
    ["/home/{$thisUser}/www/rutorrent/conf/config.php", 0754],
    ["/home/{$thisUser}/.irssi", 0750],
    ["/home/{$thisUser}/.sync", 0750],
];

$trafficPaths = pmssTrafficDataPaths($thisUser);

$chownItems = [
    ["/home/{$thisUser}/.lighttpd/.htpasswd", "{$thisUser}:{$thisUser}"],
    ["/home/{$thisUser}/.lighttpd", "{$thisUser}:{$thisUser}", true],
    // NOTE: Avoid blanket chown -R on the whole home; exclude known root-owned files/dirs first.
    // The remaining tree is handled by a targeted find below.
    ["/home/{$thisUser}/.quota", "root:{$thisUser}"],
    // Billing identity files are created root-owned by writeHomeMarker.php,
    // recreateUser restore with identity uid 0, and management-host backfill.
    // userTransfer's root-run rsync -a preserves root ownership. Readers require
    // a root owner; this pass never adopts a non-root file (see $rootArtifactWriters).
    ["/home/{$thisUser}/.billingServiceId", "root:{$thisUser}"],
    ["/home/{$thisUser}/.billingId", "root:{$thisUser}"],
    ["/home/{$thisUser}/.billingClientId", "root:{$thisUser}"],
    ["/home/{$thisUser}/.notifyEmail", "root:{$thisUser}"],
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

// Root-written files whose readers trust a root owner; see pmssUserRootArtifactWriters().
$rootArtifactWriters = pmssUserRootArtifactWriters($thisUser, $trafficPaths);

// Check each such entry once with lstat before either repair phase.
$rootArtifactRepairable = [];
foreach ($rootArtifactWriters as $artifactPath => $artifactWriter) {
    clearstatcache(true, $artifactPath);
    $rootArtifactRepairable[$artifactPath] = pmssUserRootArtifactRepairable($thisUser, $artifactPath, @lstat($artifactPath), $artifactWriter);
}

// Toggle only the traffic files present now: an optional one never written (often the
// localnet ingress file) has no flag to change. One that vanishes before the final set still logs.
// A traffic file left unadopted above is not touched at all.
$trafficFiles = array_values(array_filter($trafficPaths, static function (string $path) use ($rootArtifactRepairable): bool {
    clearstatcache(true, $path);
    return @lstat($path) !== false && ($rootArtifactRepairable[$path] ?? true);
}));

// Ensure traffic files are mutable while ownership and permissions are repaired.
foreach ($trafficFiles as $trafficFile) {
    pmssTrafficSetImmutable($trafficFile, false);
}

// Targeted chown of the home tree excluding known root-owned paths, app-managed
// trees, and bulk payload data. Healthy homes should not pay a blanket chown
// cost when uid/gid ownership is already correct.
$excludes = [
    "/home/{$thisUser}/.quota",
    "/home/{$thisUser}/.resourceData",
    // trafficLimits writes it as root and the FireQOS renderer trusts only a root-owned copy;
    // never chown it to the account, and never adopt a tenant-written one (#1056).
    "/home/{$thisUser}/.throttle",
    "/home/{$thisUser}/.billingServiceId",
    "/home/{$thisUser}/.billingId",
    "/home/{$thisUser}/.billingClientId",
    "/home/{$thisUser}/.notifyEmail",
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
$findParts[] = '-not -type l \( ! -type f -o -links 1 \)';
$uidRanges = pmssSubordinateIdRanges($thisUser, (int) $userIds['uid'], 'uid');
$gidRanges = pmssSubordinateIdRanges($thisUser, (int) $userIds['gid'], 'gid');
if ($uidRanges !== [] || $gidRanges !== []) {
    pmssUserLog($thisUser, sprintf(
        'userPermissions: honoured %d subordinate uid ranges and %d subordinate gid ranges',
        count($uidRanges),
        count($gidRanges)
    ));
}
$findParts[] = '\\(';
$findParts[] = '-not '.pmssOwnerIdSetFindPredicate('-uid', (int) $userIds['uid'], $uidRanges);
$findParts[] = '-o';
$findParts[] = '-not '.pmssOwnerIdSetFindPredicate('-gid', (int) $userIds['gid'], $gidRanges);
$findParts[] = '\\)';
$findParts[] = '-execdir chown -h';
$findParts[] = escapeshellarg($userIds['uid'].':'.$userIds['gid']);
$findParts[] = '{}';
$findParts[] = '+';
pmssUserPermissionsRun(implode(' ', $findParts));

foreach ($chownItems as $item) {
    $path = $item[0];
    if (array_key_exists($path, $rootArtifactRepairable) && !$rootArtifactRepairable[$path]) {
        continue;
    }
    $owner = $item[1];
    if (array_key_exists($path, $rootArtifactRepairable)) {
        // The file is already root-owned, so only its group can need repair. Set it
        // through the opened inode, which must still be root-owned: a file swapped in
        // after the check is never adopted, and no path is resolved twice.
        pmssUserPermissionsOpenedGroupSet($thisUser, $homeDir, $path, substr($owner, strpos($owner, ':') + 1), 0);
        continue;
    }
    $recursive = isset($item[2]) ? (bool)$item[2] : false;
    $predicate = $recursive ? pmssFindOwnerMismatchPredicate($owner) : '';
    pmssUserPermissionsOwnerApply($homeDir, $path, $owner, $predicate, $predicate !== '');
}

// Modes are set after ownership so account-owned entries already belong to the
// account that changes them; directories it does not own are left out. Directories
// first; the explicit items below override.
pmssUserPermissionsAccountRun($thisUser, $homeDir, [$homeDir], sprintf(
    'find %s \( -path %s -o -type d -not -uid %d \) -prune -o -type d -not -perm 0750 -exec chmod 0750 {} +',
    escapeshellarg('/home/'.$thisUser),
    escapeshellarg("/home/{$thisUser}/.local"),
    $accountUid
));

// Existing-home residue of the #781 skel-mode regression: served ~/www content was
// copied carrying the exec bit. Strip exec from ~/www FILES (dirs are already normalised
// to 0750 by the walk above). Bounded to ~/www so large payload trees under ~/data are
// untouched; the explicit chmodItems below re-apply any file that legitimately keeps exec.
$wwwDir = "/home/{$thisUser}/www";
pmssUserPermissionsAccountRun($thisUser, $homeDir, [$wwwDir], sprintf('find %s -type d -not -uid %d -prune -o -type f -links 1 -uid %d -perm /0111 -exec chmod a-x {} +', escapeshellarg($wwwDir), $accountUid, $accountUid));

foreach ($chmodItems as $item) {
    $path = $item[0];
    if (array_key_exists($path, $rootArtifactRepairable) && !$rootArtifactRepairable[$path]) {
        continue;
    }
    $perm = $item[1];
    $recursive = isset($item[2]) ? (bool)$item[2] : false;
    pmssUserPermissionsModeApply($thisUser, $homeDir, $accountUid, $path, $perm, $recursive);
}

if (file_exists("/home/{$thisUser}/.ssh")) {
    pmssUserPermissionsModeApply($thisUser, $homeDir, $accountUid, "/home/{$thisUser}/.ssh", 0750);
}

foreach ($trafficFiles as $trafficFile) {
    pmssTrafficSetImmutable($trafficFile, true);
}

exit(pmssUserPermissionsResult());
