<?php
/**
 * Install or upgrade pyLoad (pyload-ng) using a Python 3 virtual environment.
 */

require_once __DIR__.'/packages/helpers.php';

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

$dryRun = getenv('PMSS_DRY_RUN') === '1';
$python = trim((string) @shell_exec('command -v python3 2>/dev/null'));
if ($python === '') {
    $logger('[WARN] Skipping pyLoad setup: python3 missing from PATH');
    return;
}

$venvDir   = '/opt/pyload';
$pythonBin = $venvDir.'/bin/python';
$pipBin    = $venvDir.'/bin/pip';
$cliBin    = $venvDir.'/bin/pyload';

$aptDeps = [
    'python3',
    'python3-venv',
    'python3-pip',
    'python3-setuptools',
    'python3-distutils',
    'libffi-dev',
    'libssl-dev',
    'libjpeg-dev',
    'zlib1g-dev',
];
runStep('Installing pyLoad apt dependencies', aptCmd('install -y '.implode(' ', array_map('escapeshellarg', $aptDeps))));

if (!is_dir($venvDir)) {
    runStep('Creating pyLoad virtualenv', sprintf('%s -m venv %s', escapeshellarg($python), escapeshellarg($venvDir)));
}

if (!is_file($pythonBin)) {
    if ($dryRun) {
        return;
    }
    $logger('[WARN] pyLoad virtualenv missing python binary after creation');
    return;
}

// Some Debian builds create venvs without pip; bootstrap it if missing.
if (!is_file($pipBin)) {
    runStep('Bootstrapping pip in pyLoad virtualenv', sprintf('%s -m ensurepip --upgrade', escapeshellarg($pythonBin)));
}

// If pip is still unavailable, fail soft and skip the install to avoid repeated errors.
if (!is_file($pipBin)) {
    if (!$dryRun) {
        $logger('[ERR] pyLoad virtualenv missing pip; ensure python3-venv is installed and rerun update');
    }
    return;
}

runStep('Upgrading pyLoad virtualenv tooling', sprintf('%s -m pip install --upgrade pip setuptools wheel', escapeshellarg($pythonBin)));
runStep('Installing pyLoad (pyload-ng)', sprintf('%s -m pip install --upgrade pyload-ng', escapeshellarg($pythonBin)));

if (is_file($cliBin)) {
    if (!is_link('/usr/local/bin/pyload') || readlink('/usr/local/bin/pyload') !== $cliBin) {
        runStep('Linking pyLoad CLI', sprintf('ln -sf %s %s', escapeshellarg($cliBin), escapeshellarg('/usr/local/bin/pyload')));
    }
} elseif (!$dryRun) {
    $logger('[WARN] pyLoad binary missing after install');
}
