#!/usr/bin/php
<?php
# Set user folder permissions

$usage = 'Usage: ./userPermissions.php USERNAME';
if (empty($argv[1]) ) die('need user name. ' . $usage . "\n");
    
$thisUser = $argv[1];
#TODO(user-logs): log major permission/ownership corrections to /var/log/pmss/user-<username>.log
if (!file_exists("/home/{$thisUser}")) die("User does not exist\n");
$userList = file_get_contents('/etc/passwd');
if (strpos($userList, $thisUser) === false) die("No such user\n");

function run(string $cmd): int
{
    $rc = 0;
    passthru($cmd, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "Command failed (rc={$rc}): {$cmd}\n");
    }
    return $rc;
}

function chmodPath(string $path, int $perm, bool $recursive = false): void
{
    $flag = $recursive ? '-R ' : '';
    $target = strpbrk($path, '*?[]') === false ? escapeshellarg($path) : $path;
    run(sprintf('chmod %s%o %s', $flag, $perm, $target));
}

function chownPath(string $path, string $owner, bool $recursive = false): void
{
    $flag = $recursive ? '-R ' : '';
    $target = strpbrk($path, '*?[]') === false ? escapeshellarg($path) : $path;
    // Quote owner spec as a single argument; chown accepts quoted 'user.group'
    run(sprintf('chown %s%s %s', $flag, escapeshellarg($owner), $target));
}

// Safer traversal without relying on xargs delimiters; applies to each directory in place.
run(sprintf('find %s -type d -exec chmod 750 {} +', escapeshellarg('/home/'.$thisUser)));

$chmodItems = [
    ["/home/{$thisUser}", 0770],
    ["/home/{$thisUser}/.viminfo", 0640],
    ["/home/{$thisUser}/.quota", 0640],
    ["/home/{$thisUser}/.profile", 0640],
    ["/home/{$thisUser}/.bash_history", 0640],
    ["/home/{$thisUser}/.bashrc", 0640],
    ["/home/{$thisUser}/.tmp", 0770],
    ["/home/{$thisUser}/.config", 0770, true],
    ["/home/{$thisUser}/.trafficData", 0640],
    ["/home/{$thisUser}/.rtorrent.rc", 0644],
    ["/home/{$thisUser}/watch", 0750, true],
    ["/home/{$thisUser}/session", 0750, true],
    ["/home/{$thisUser}/data", 0750, true],
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

$chownItems = [
    ["/home/{$thisUser}/.lighttpd/.htpasswd", "{$thisUser}.{$thisUser}"],
    ["/home/{$thisUser}/.lighttpd/", "{$thisUser}.{$thisUser}", true],
    // NOTE: Avoid blanket chown -R on the whole home; exclude known root-owned files/dirs first.
    // The remaining tree is handled by a targeted find below.
    ["/home/{$thisUser}/.trafficData", "root.{$thisUser}"],
    ["/home/{$thisUser}/.trafficDataLocal", "root.{$thisUser}"],
    ["/home/{$thisUser}/www/rutorrent/share/users/{$thisUser}/settings", "{$thisUser}.{$thisUser}"],
    ["/home/{$thisUser}/www/rutorrent/share/users/{$thisUser}/settings/retrackers.dat", "{$thisUser}.{$thisUser}"],
    ["/home/{$thisUser}/www/rutorrent/share/users/{$thisUser}", "{$thisUser}.{$thisUser}"],
    ["/home/{$thisUser}/www/rutorrent/share/users/{$thisUser}/torrents", "{$thisUser}.{$thisUser}"],
    ["/home/{$thisUser}/.rtorrent.rc", "root.root"],
    ["/home/{$thisUser}/www/rutorrent/conf/config.php", "root.root"],
];

foreach ($chmodItems as [$path, $perm, $recursive]) {
    chmodPath($path, $perm, $recursive ?? false);
}

// Targeted chown of the home tree excluding known root-owned paths
$excludes = [
    "/home/{$thisUser}/.trafficData",
    "/home/{$thisUser}/.trafficDataLocal",
    "/home/{$thisUser}/.rtorrent.rc",
    "/home/{$thisUser}/www/rutorrent/conf/config.php",
];
$findParts = [sprintf('find %s -mindepth 1', escapeshellarg("/home/{$thisUser}"))];
foreach ($excludes as $ex) {
    $findParts[] = '-not -path '.escapeshellarg($ex);
}
$findParts[] = '-exec chown';
$findParts[] = escapeshellarg("{$thisUser}.{$thisUser}");
$findParts[] = '{}';
$findParts[] = '+';
run(implode(' ', $findParts));

foreach ($chownItems as [$path, $owner, $recursive]) {
    chownPath($path, $owner, $recursive ?? false);
}

if (file_exists("/home/{$thisUser}/.ssh")) {
    chmodPath("/home/{$thisUser}/.ssh", 0750);
}
