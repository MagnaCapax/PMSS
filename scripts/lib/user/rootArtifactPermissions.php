<?php
/** Decide whether a root-provisioned in-home file may be repaired by userPermissions.php. */

/**
 * Root-written in-home files whose readers trust a root owner (notification recipient,
 * usage alerts, operator traffic report), mapped to their authoritative writer. The
 * account can replace these names, so only a file that is already root-owned is repaired.
 *
 * @param array{normal:string,local:string,ingress:string,ingressLocal:string} $trafficPaths
 * @return array<string, string> path => writer
 */
function pmssUserRootArtifactWriters(string $user, array $trafficPaths): array
{
    return [
        "/home/{$user}/.billingServiceId" => 'scripts/util/writeHomeMarker.php',
        "/home/{$user}/.billingId" => 'scripts/util/writeHomeMarker.php',
        "/home/{$user}/.billingClientId" => 'scripts/util/writeHomeMarker.php',
        "/home/{$user}/.notifyEmail" => 'billing-system provisioning',
        "/home/{$user}/.quota" => 'scripts/cron/updateQuotas.php',
        $trafficPaths['normal'] => 'scripts/cron/trafficStats.php',
        $trafficPaths['local'] => 'scripts/cron/trafficStats.php',
        $trafficPaths['ingress'] => 'scripts/cron/trafficIngressStats.php',
        $trafficPaths['ingressLocal'] => 'scripts/cron/trafficIngressStats.php',
    ];
}

/**
 * Accept only a root-owned regular entry. The caller supplies lstat() metadata so
 * symlinks are never dereferenced and one decision gates both mode and owner repair.
 * The account owns its home and can replace these names, and their readers trust a
 * root owner, so adopting a non-root file would make a tenant-written value trusted.
 */
function pmssUserRootArtifactRepairable(string $user, string $path, $stat, string $writer): bool
{
    if (!is_array($stat)) {
        return false;
    }

    $name = basename($path);
    if (($stat['mode'] & 0170000) !== 0100000) {
        pmssUserLog($user, "userPermissions: {$name} is not a regular file; left as-is");
        return false;
    }

    if ($stat['uid'] !== 0) {
        pmssUserLog($user, sprintf(
            'userPermissions: %s owner uid=%d; left unadopted because readers require a root-owned file; authoritative writer: %s',
            $name,
            $stat['uid'],
            $writer
        ));
        return false;
    }

    return true;
}
