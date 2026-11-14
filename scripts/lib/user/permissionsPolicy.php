<?php
/** Ordered mode and owner policy for account permission convergence. */

/** Keep the two phase orders explicit; these lists do not infer trust from filenames. */
function pmssUserPermissionsPolicy(string $thisUser, array $trafficPaths): array
{
    return [[
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
        // Strip-only: keep owner-only modes, clear other bits and setuid/setgid (#1217).
        ["/home/{$thisUser}/.config", 0770, true, true],
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
        // ruTorrent launches these helpers directly; restore exec after the #781 strip (#1011).
        ["/home/{$thisUser}/www/rutorrent/plugins/create/inner.sh", 0750],
        ["/home/{$thisUser}/www/rutorrent/plugins/create/buildtorrent.sh", 0750],
        ["/home/{$thisUser}/www/rutorrent/plugins/create/createtorrent.sh", 0750],
        ["/home/{$thisUser}/www/rutorrent/plugins/create/mktorrent.sh", 0750],
        ["/home/{$thisUser}/www/rutorrent/plugins/create/transmissioncli.sh", 0750],
        ["/home/{$thisUser}/www/rutorrent/plugins/create/transmissioncreate.sh", 0750],
        ["/home/{$thisUser}/www/rutorrent/plugins/erasedata/fin.sh", 0750],
        ["/home/{$thisUser}/www/rutorrent/plugins/unpack/unall_dir.sh", 0750],
        ["/home/{$thisUser}/www/rutorrent/plugins/unpack/unrar_dir.sh", 0750],
        ["/home/{$thisUser}/www/rutorrent/plugins/unpack/unrar_file.sh", 0750],
        ["/home/{$thisUser}/www/rutorrent/plugins/unpack/unzip_dir.sh", 0750],
        ["/home/{$thisUser}/www/rutorrent/plugins/unpack/unzip_file.sh", 0750],
        ["/home/{$thisUser}/.irssi", 0750],
        ["/home/{$thisUser}/.sync", 0750],
    ], [
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
    ]];
}
