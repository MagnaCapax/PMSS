<?php
/**
 * addUser: required artifact verification before reporting success.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Abort provisioning if any required artifact is still missing.
 */
function pmssAddUserVerifyArtifactsOrFail(string $userName, string $homePath): void
{
    // Keep the required artifacts adjacent to the fatal guard so the success
    // contract stays readable without chasing a one-call helper.
    $requiredArtifacts = array(
        'nginx_config' => '/etc/nginx/users/'.$userName,
        'rtorrent_config' => $homePath.'/.rtorrent.rc',
        'lighttpd_config' => $homePath.'/.lighttpd.conf',
        'quota_snapshot' => $homePath.'/.quota',
    );

    foreach ($requiredArtifacts as $label => $path) {
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
