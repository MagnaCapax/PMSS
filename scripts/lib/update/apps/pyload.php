<?php
/**
 * Install or upgrade pyLoad (pyload-ng) using a Python 3 virtual environment.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/packages/helpers.php';
require_once __DIR__.'/pythonVenv.php';

$logger = function (string $message): void {
    if (function_exists('logmsg')) {
        logmsg($message);
    } else {
        echo $message."\n";
    }
};

$distroVersion = (int) (getenv('PMSS_DISTRO_VERSION') ?: 0);
if ($distroVersion > 0 && $distroVersion < 10) {
    $logger('[WARN] Skipping pyLoad setup: unsupported Debian release');
    return;
}

$python = trim((string) @shell_exec('command -v python3 2>/dev/null'));
if ($python === '') {
    $logger('[WARN] Skipping pyLoad setup: python3 missing from PATH');
    return;
}

$venvDir   = '/opt/pyload';
$cliBin    = $venvDir.'/bin/pyload';

// Required Python toolchain packages are queued centrally via packages.php

$venv = pmssPythonVenvEnsure($venvDir, 'pyLoad', $logger);
if (empty($venv)) {
    return;
}

runStep('Installing pyLoad (pyload-ng)', sprintf('%s -m pip install --upgrade pyload-ng', escapeshellarg($venv['python'])));

if (is_file($cliBin)) {
    if (!is_link('/usr/local/bin/pyload') || readlink('/usr/local/bin/pyload') !== $cliBin) {
        runStep('Linking pyLoad CLI', sprintf('ln -sf %s %s', escapeshellarg($cliBin), escapeshellarg('/usr/local/bin/pyload')));
    }
} elseif (getenv('PMSS_DRY_RUN') !== '1') {
    $logger('[WARN] pyLoad binary missing after install');
}
