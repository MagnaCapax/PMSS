<?php
/**
 * Map a source account's numeric owners into only the target account's IDs.
 * The rsync receiver is namespace root, which is the target UID on the host.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__).'/user/subordinateIds.php';

/** Obtain the source account and subordinate ranges without trusting names in rsync's file list. */
function pmssUserTransferBuildIdentityProbe(array $cfg): string
{
    $user = escapeshellarg($cfg['remoteUser']);
    $remote = 'uid=$(id -u); gid=$(id -g); printf "\\nPMSS_TRANSFER_IDENTITY_BEGIN uid=%s gid=%s\\n" "$uid" "$gid"; '
        .'for kind in uid gid; do file=/etc/sub$kind; test -r "$file" || exit 1; '
        .'case "$kind" in uid) primary=$uid ;; gid) primary=$gid ;; esac; '
        .'awk -F: -v user='.$user.' -v primary="$primary" -v kind="$kind" '
        .escapeshellarg('$1 == user || $1 == primary { print "sub" kind "=" $2 ":" $3 }').' "$file"; done; '
        .'printf "PMSS_TRANSFER_IDENTITY_END\\n"';
    return "#!/bin/bash\nset -e\n".pmssUserTransferBuildSshProbeCommand($cfg, $remote)."\n";
}

/** Reject malformed, duplicate, overlapping, or unrepresentable source ID declarations. */
function pmssUserTransferParseIdentity(string $output): array
{
    $begin = 'PMSS_TRANSFER_IDENTITY_BEGIN ';
    $start = strpos($output, $begin);
    $end = $start === false ? false : strpos($output, 'PMSS_TRANSFER_IDENTITY_END', $start + strlen($begin));
    if ($start === false || $end === false) {
        throw new RuntimeException('Source identity probe output is incomplete', 1);
    }
    $body = substr($output, $start + strlen($begin), $end - $start - strlen($begin));
    $lines = preg_split('/\r?\n/', trim($body));
    if (!is_array($lines) || !preg_match('/^uid=([0-9]+) gid=([0-9]+)$/D', $lines[0] ?? '', $match)) {
        throw new RuntimeException('Source identity probe returned invalid IDs', 1);
    }
    $identity = ['uid' => (int) $match[1], 'gid' => (int) $match[2], 'subuid' => [], 'subgid' => []];
    if ($identity['uid'] < 1000 || $identity['gid'] < 1000
        || $identity['uid'] > 4294967295 || $identity['gid'] > 4294967295
        || (string) $identity['uid'] !== $match[1] || (string) $identity['gid'] !== $match[2]) {
        throw new RuntimeException('Source account has unsafe primary IDs', 1);
    }
    foreach (array_slice($lines, 1) as $line) {
        if (!preg_match('/^(subuid|subgid)=([0-9]+):([0-9]+)$/D', $line, $parts)) {
            throw new RuntimeException('Source identity probe returned invalid ranges', 1);
        }
        $start = (int) $parts[2];
        $count = (int) $parts[3];
        if ($start < 100000 || $count < 1 || $count > 1048576 || $start > 4294967295 - $count
            || (string) $start !== $parts[2] || (string) $count !== $parts[3]
            || count($identity[$parts[1]]) >= 16) {
            throw new RuntimeException('Source subordinate range is unsafe', 1);
        }
        foreach ($identity[$parts[1]] as $range) {
            if ($start < $range[0] + $range[1] && $range[0] < $start + $count) {
                throw new RuntimeException('Source subordinate ranges overlap', 1);
            }
        }
        $identity[$parts[1]][] = [$start, $count];
    }
    foreach (['uid' => 'subuid', 'gid' => 'subgid'] as $primary => $kind) {
        foreach ($identity[$kind] as $range) {
            if ($identity[$primary] >= $range[0] && $identity[$primary] < $range[0] + $range[1]) {
                throw new RuntimeException('Source primary ID overlaps a subordinate range', 1);
            }
        }
    }
    return $identity;
}

/** Pair source subordinate ranges with the target's allocation; refuse an undersized target. */
function pmssUserTransferIdMap(array $sourceRanges, array $targetRanges, int $targetId): string
{
    usort($sourceRanges, static function (array $a, array $b): int { return $a[0] <=> $b[0]; });
    usort($targetRanges, static function (array $a, array $b): int { return $a[0] <=> $b[0]; });
    $previousEnd = 0;
    foreach ($targetRanges as $range) {
        if ($range[0] < $previousEnd || ($targetId >= $range[0] && $targetId < $range[0] + $range[1])) {
            throw new RuntimeException('Target subordinate ID allocation overlaps', 1);
        }
        $previousEnd = $range[0] + $range[1];
    }
    $lines = ['0 '.$targetId.' 1'];
    $targetIndex = 0;
    $targetOffset = 0;
    foreach ($sourceRanges as $source) {
        $sourceStart = $source[0];
        $left = $source[1];
        while ($left > 0) {
            if (!isset($targetRanges[$targetIndex])) {
                throw new RuntimeException('Target subordinate ID allocation is too small', 1);
            }
            $available = $targetRanges[$targetIndex][1] - $targetOffset;
            $length = min($left, $available);
            $lines[] = $sourceStart.' '.($targetRanges[$targetIndex][0] + $targetOffset).' '.$length;
            $sourceStart += $length;
            $left -= $length;
            $targetOffset += $length;
            if ($targetOffset === $targetRanges[$targetIndex][1]) {
                $targetIndex++;
                $targetOffset = 0;
            }
        }
    }
    return implode("\n", $lines)."\n";
}

/** Build the root launcher; only the account-mapped child executes rsync. */
function pmssUserTransferBuildReceiver(array $cfg, string $rsync): string
{
    if (!isset($cfg['receiver'])) {
        throw new RuntimeException('Receiver namespace was not prepared', 1);
    }
    $receiver = $cfg['receiver'];
    $inner = 'for i in {1..500}; do [ "$(id -u)" = 0 ] && break; sleep 0.02; done; '
        .'[ "$(id -u)" = 0 ] || exit 1; exec '.$rsync;
    // Expect keeps the password in the root parent; the account-owned child
    // must never inherit it through /proc/<pid>/environ.
    $launch = 'env -u PMSS_USER_TRANSFER_PASSWORD setpriv --reuid='.$receiver['uid'].' --regid='.$receiver['gid'].' --clear-groups '
        .'unshare -U bash -c '.escapeshellarg($inner).' &' . "\n"
        .'child=$!' . "\n"
        ."trap 'kill \"\$child\" 2>/dev/null || true' EXIT\n"
        .'for i in {1..500}; do' . "\n"
        .'  [ -e "/proc/$child/uid_map" ] || exit 1' . "\n"
        .'  [ "$(readlink "/proc/$child/ns/user")" != "$(readlink /proc/self/ns/user)" ] && break' . "\n"
        .'  sleep 0.02' . "\n"
        .'done' . "\n"
        .'[ "$(readlink "/proc/$child/ns/user")" != "$(readlink /proc/self/ns/user)" ] || exit 1' . "\n"
        .'/usr/bin/printf %s '.escapeshellarg($receiver['uidMap']).' > "/proc/$child/uid_map"' . "\n"
        .'printf %s deny > "/proc/$child/setgroups"' . "\n"
        .'/usr/bin/printf %s '.escapeshellarg($receiver['gidMap']).' > "/proc/$child/gid_map"' . "\n"
        .'wait "$child"' . "\n";

    // Mounts are private before any remount. A bind of this home remains writable
    // while every other writable mount is made read-only inside the child view.
    $home = escapeshellarg($receiver['home']);
    $mount = 'home='.$home."\n"
        .'[ "$(cat /proc/sys/fs/protected_symlinks)" = 1 ]' . "\n"
        .'[ "$(cat /proc/sys/fs/protected_hardlinks)" = 1 ]' . "\n"
        .'mount --make-rprivate /' . "\n"
        .'mount --bind "$home" "$home"' . "\n"
        .'mounts=$(findmnt -nro TARGET -R /)' . "\n"
        .'[ -n "$mounts" ] || exit 1' . "\n"
        .'while IFS= read -r point; do' . "\n"
        .'  case "$point" in "$home"|"$home"/*|/proc|/proc/*|/sys|/sys/*|/var/lib/docker/*|/run/docker/*) continue ;; esac' . "\n"
        .'  mount -o remount,bind,ro "$point" || exit 1' . "\n"
        .'done <<< "$mounts"' . "\n"
        .$launch;
    return 'unshare -m bash -c '.escapeshellarg("set -e\n".$mount)."\n";
}

/** Resolve the target's real allocation before any receiver is launched. */
function pmssUserTransferReceiverConfig(string $user, string $home, array $source): array
{
    $account = pmssUserAccountLookup($user);
    if (!is_array($account) || !isset($account['uid'], $account['gid'])) {
        throw new RuntimeException('Target account identity is unavailable', 1);
    }
    $uid = (int) $account['uid'];
    $gid = (int) $account['gid'];
    return [
        'uid' => $uid, 'gid' => $gid, 'home' => $home,
        'uidMap' => pmssUserTransferIdMap($source['subuid'], pmssSubordinateIdRanges($user, $uid, 'uid'), $uid),
        'gidMap' => pmssUserTransferIdMap($source['subgid'], pmssSubordinateIdRanges($user, $gid, 'gid'), $gid),
        'sourceUid' => $source['uid'], 'sourceGid' => $source['gid'],
    ];
}
