<?php
/**
 * Docker and rootless prerequisites – queue packages for package phase.
 */

require_once __DIR__.'/../packages/helpers.php';

function pmssInstallDockerPackages(int $distroVersion): void
{
    $pkgs = [
        'docker-ce', 'docker-ce-cli', 'containerd.io', 'docker-buildx-plugin', 'docker-compose-plugin',
        'dbus-user-session', 'slirp4netns', 'uidmap',
    ];
    if ($distroVersion < 12) { $pkgs[] = 'fuse-overlayfs'; }
    pmssQueuePackages($pkgs);
}
