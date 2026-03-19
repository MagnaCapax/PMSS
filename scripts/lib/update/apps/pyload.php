<?php
/**
 * Install or upgrade pyLoad (pyload-ng) using a Python 3 virtual environment.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/pythonVenv.php';

if (($distroVersion = (int) (getenv('PMSS_DISTRO_VERSION') ?: 0)) > 0 && $distroVersion < 10) {
    logmsg('[WARN] Skipping pyLoad setup: unsupported Debian release');
    return;
}

$venvDir   = '/opt/pyload';
$cliBin    = $venvDir.'/bin/pyload';

// Required Python toolchain packages are queued centrally via packages.php

$venv = pmssPythonVenvEnsure($venvDir, 'pyLoad', 'logmsg', '[WARN] Skipping pyLoad setup: python3 missing from PATH');
if (empty($venv)) {
    return;
}

runStep('Installing pyLoad (pyload-ng)', sprintf('%s -m pip install --upgrade pyload-ng', escapeshellarg($venv['python'])));

if (!is_file($cliBin)) {
    if (getenv('PMSS_DRY_RUN') !== '1') logmsg('[WARN] pyLoad binary missing after install');
    return;
}

if (!is_link('/usr/local/bin/pyload') || readlink('/usr/local/bin/pyload') !== $cliBin) {
    runStep('Linking pyLoad CLI', sprintf('ln -sf %s %s', escapeshellarg($cliBin), escapeshellarg('/usr/local/bin/pyload')));
}
