<?php
/**
 * addUser: required artifact verification before reporting success.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Return the canonical artifact paths that must exist after provisioning.
 *
 * These files back the minimum viable PMSS user experience: nginx routing,
 * rtorrent config, per-user lighttpd config, and a seeded quota snapshot.
 *
 * @return array<string, string>
 */
function pmssAddUserRequiredArtifactPaths(string $userName, string $homePath): array
{
    return array(
        'nginx_config' => pmssAddUserExpectedNginxConfigPath($userName),
        'rtorrent_config' => $homePath.'/.rtorrent.rc',
        'lighttpd_config' => $homePath.'/.lighttpd.conf',
        'quota_snapshot' => $homePath.'/.quota',
    );
}

/**
 * Abort provisioning if any required artifact is still missing.
 */
function pmssAddUserVerifyArtifactsOrFail(string $userName, string $homePath): void
{
    foreach (pmssAddUserRequiredArtifactPaths($userName, $homePath) as $label => $path) {
        if (is_file($path)) {
            continue;
        }

        pmssAddUserFatalExit(
            'FAIL',
            'Missing required provisioning artifact: '.$path,
            'missing_artifact',
            array(
                'artifact' => $label,
                'path' => $path,
            )
        );
    }
}
