<?php
/**
 * Context-aware Docker inactive note helpers for the web stats page.
 *
 * Keep the page guidance aligned with the real boot/cgroup state so users are
 * not told to change GRUB when Docker is simply stopped or disabled.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Build the Docker inactive note shown beside the Docker app status.
 *
 * The note is only shown for the inactive state. When the cgroup boot option is
 * already present, the note points users to the existing Docker controls rather
 * than implying a host-level GRUB problem.
 *
 * @param string     $dockerStatus        Current status label from the stats page.
 * @param bool|null  $dockerEnabledPolicy Canonical per-user Docker policy.
 */
function pmssWebDockerInactiveNote(
    string $dockerStatus,
    ?bool $dockerEnabledPolicy,
    string $osReleasePath = '/etc/os-release',
    string $debianVersionPath = '/etc/debian_version',
    string $cmdlinePath = '/proc/cmdline'
): string {
    if ($dockerStatus !== 'inactive') {
        return '';
    }

    $cmdline = (string) @file_get_contents($cmdlinePath);
    if (strpos($cmdline, 'unified_cgroup_hierarchy=0') === false) {
        $debianLabel = 'Debian';
        $osRelease = @parse_ini_file($osReleasePath);
        if (is_array($osRelease)) {
            $versionId = trim((string) ($osRelease['VERSION_ID'] ?? ''));
            if ($versionId !== '') {
                $debianLabel = preg_match('/^([0-9]+)/', $versionId, $matches) === 1
                    ? 'Debian '.$matches[1]
                    : 'Debian '.$versionId;
            }
        }
        if ($debianLabel === 'Debian') {
            $debianVersion = trim((string) @file_get_contents($debianVersionPath));
            if (preg_match('/^([0-9]+)/', $debianVersion, $matches) === 1) {
                $debianLabel = 'Debian '.$matches[1];
            }
        }
        return sprintf(
            ' (%s: User bus restricted. Requires `systemd.unified_cgroup_hierarchy=0` in GRUB. Contact support.)',
            $debianLabel
        );
    }

    if ($dockerEnabledPolicy === false) {
        return ' (Docker is available but currently disabled. Use the controls below to enable it.)';
    }

    if ($dockerEnabledPolicy === true) {
        return ' (Docker is available but not currently running. Use the controls below to start it again.)';
    }

    return ' (Docker is available but not currently running. Use the Docker controls below to enable it.)';
}
