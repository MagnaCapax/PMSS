<?php
/** Ordered account permission convergence; the CLI owns identity validation and locking. */

require_once __DIR__.'/permissionsApply.php';
require_once __DIR__.'/rootArtifactPermissions.php';
require_once __DIR__.'/directories.php';
require_once __DIR__.'/permissionsPolicy.php';
require_once __DIR__.'/permissionsHome.php';
require_once __DIR__.'/permissionsOwnership.php';
require_once __DIR__.'/../traffic/storage.php';

/** Preserve home preparation, trust checks, mutable repair phases and final immutable sets. */
function pmssUserPermissionsConverge(string $thisUser, string $homeDir, array $userIds): void
{
    $accountUid = (int) $userIds['uid'];
    pmssUserPermissionsHomePrepare($thisUser, $homeDir, $userIds);
    $trafficPaths = pmssTrafficDataPaths($thisUser);
    [$chmodItems, $chownItems] = pmssUserPermissionsPolicy($thisUser, $trafficPaths);
    // Root-written files whose readers trust a root owner; see pmssUserRootArtifactWriters().
    // Check each such entry once with lstat before either repair phase.
    $rootArtifactRepairable = [];
    foreach (pmssUserRootArtifactWriters($thisUser, $trafficPaths) as $artifactPath => $artifactWriter) {
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

    pmssUserPermissionsOwnerWalk($thisUser, $userIds, $trafficPaths);

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
        // The only recursive owner policy is the account's own lighttpd tree.
        $predicate = !empty($item[2]) ? sprintf('\( -not -user %s -o -not -group %s \)',
            escapeshellarg($thisUser), escapeshellarg($thisUser)) : '';
        pmssUserPermissionsOwnerApply($homeDir, $path, $owner, $predicate, $predicate !== '');
    }

    // Modes are set after ownership so account-owned entries already belong to the
    // account that changes them; directories it does not own are left out. Directories
    // first; the explicit items below override. Stay on the home filesystem, still
    // including ordinary ~/data directories, and tolerate concurrent cache removal.
    pmssUserPermissionsAccountRun($thisUser, $homeDir, [$homeDir], sprintf(
        'find %s -xdev -ignore_readdir_race \( -path %s -o -path %s -o -type d -not -uid %d \) -prune -o -type d -not -perm 0750 -exec chmod 0750 {} +',
        escapeshellarg('/home/'.$thisUser),
        escapeshellarg("/home/{$thisUser}/.local"),
        escapeshellarg("/home/{$thisUser}/.config"),
        $accountUid
    ));

    // Existing-home residue of the #781 skel-mode regression: served ~/www content was
    // copied carrying the exec bit. Strip exec from ~/www FILES (dirs are already normalised
    // to 0750 by the walk above). Bounded to ~/www so large payload trees under ~/data are
    // untouched; the explicit chmodItems below re-apply any file that legitimately keeps exec.
    $wwwDir = "/home/{$thisUser}/www";
    pmssUserPermissionsAccountRun($thisUser, $homeDir, [$wwwDir], sprintf('find %s -xdev -ignore_readdir_race -type d -not -uid %d -prune -o -type f -links 1 -uid %d -perm /0111 -exec chmod a-x {} +', escapeshellarg($wwwDir), $accountUid, $accountUid));

    foreach ($chmodItems as $item) {
        $path = $item[0];
        if (array_key_exists($path, $rootArtifactRepairable) && !$rootArtifactRepairable[$path]) {
            continue;
        }
        $perm = $item[1];
        $recursive = isset($item[2]) ? (bool)$item[2] : false;
        pmssUserPermissionsModeApply($thisUser, $homeDir, $accountUid, $path, $perm, $recursive, !empty($item[3]));
    }

    if (file_exists("/home/{$thisUser}/.ssh")) {
        pmssUserPermissionsModeApply($thisUser, $homeDir, $accountUid, "/home/{$thisUser}/.ssh", 0750);
    }

    foreach ($trafficFiles as $trafficFile) {
        pmssTrafficSetImmutable($trafficFile, true);
    }
}
