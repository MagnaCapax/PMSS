<?php
/**
 * Install or upgrade pyLoad (pyload-ng) using a Python 3 virtual environment.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/pythonVenv.php';
require_once __DIR__.'/../distro.php';
if (($distroVersion = pmssDistroVersionFromEnv()) > 0 && $distroVersion < 10) {
    logmsg('[WARN] Skipping pyLoad setup: unsupported Debian release');
    return;
}

$venvDir   = '/opt/pyload';
$cliBin    = $venvDir.'/bin/pyload';

// Required Python toolchain packages are queued centrally via packages.php
pmssPythonVenvInstallCli($venvDir, 'pyLoad', [['Installing pyLoad (pyload-ng)', 'pyload-ng']], $cliBin, '/usr/local/bin/pyload', '[WARN] Skipping pyLoad setup: python3 missing from PATH', '[WARN] pyLoad binary missing after install', 'logmsg');
