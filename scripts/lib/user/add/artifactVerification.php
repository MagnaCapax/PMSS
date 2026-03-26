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
 * Return the first missing required provisioning artifact, if any.
 *
 * @return array<string, string>|null
 */
function pmssAddUserMissingArtifact(string $userName, string $homePath): ?array
{
    foreach (pmssAddUserRequiredArtifactPaths($userName, $homePath) as $label => $path) {
        if (!is_file($path)) {
            return array(
                'label' => $label,
                'path' => $path,
            );
        }
    }

    return null;
}

/**
 * Abort provisioning if any required artifact is still missing.
 */
function pmssAddUserVerifyArtifactsOrFail(string $userName, string $homePath): void
{
    $missing = pmssAddUserMissingArtifact($userName, $homePath);
    if ($missing === null) {
        return;
    }

    logProvisionMessage('FATAL: Missing required provisioning artifact: '.$missing['path']);
    finalizeProvision(
        'FAIL',
        'missing_artifact',
        1,
        array(
            'artifact' => $missing['label'],
            'path' => $missing['path'],
        )
    );
    exit(1);
}
