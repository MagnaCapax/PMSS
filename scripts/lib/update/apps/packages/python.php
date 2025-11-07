<?php
/**
 * Python ecosystems and related tooling.
 */

require_once __DIR__.'/helpers.php';

function pmssInstallPythonToolchain(int $distroVersion): void
{
    pmssQueuePackages([
        'libffi-dev',
        'libssl-dev',
        'libjpeg-dev',
        'zlib1g-dev',
        'python3',
        'python3-dev',
        'python3-venv',
        'python3-virtualenv',
        'python3-pip',
        'python3-setuptools',
        'python3-wheel',
    ]);
}

function pmssInstallZncStack(int $distroVersion): void
{
    if ($distroVersion < 10) {
        logmsg('[WARN] Skipping ZNC stack: unsupported Debian release');
        return;
    }

    pmssQueuePackages([
        'znc',
        'znc-perl',
        'znc-tcl',
        'znc-python3',
        'git',
        'intltool',
        'librsvg2-common',
        'xdg-utils',
        'geoip-database',
        'python3-notify2',
        'python3-pygame',
        'python3-gi',
        'python3-mako',
        'python3-setproctitle',
        'python3-openssl',
        'python3-twisted',
        'python3-chardet',
        'python3-xdg',
        'python3-libtorrent',
    ]);

    // ffmpeg ships via the dpkg baselines; no additional queueing required here.
}
