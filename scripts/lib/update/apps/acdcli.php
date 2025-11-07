<?php
/**
 * acd_cli helper installation.
 *
 * Installs or refreshes acd_cli via pip so legacy automation keeps working
 * until the helper is migrated to a dedicated virtualenv.
 */

require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../logging.php';

$dryRun = getenv('PMSS_DRY_RUN') === '1';
// #TODO Pin acd_cli to a specific commit/tag to avoid unbounded upgrades.

$python = trim((string) @shell_exec('command -v python3 2>/dev/null'));
if ($python === '') {
    logmsg('[WARN] Skipping acd_cli install: python3 missing');
    return;
}

runStep('Ensuring Python tooling for acd_cli', aptCmd('install -y python3 python3-venv python3-pip'));

$venvDir   = '/opt/acd_cli';
$pythonBin = $venvDir.'/bin/python';
$pipBin    = $venvDir.'/bin/pip';
$cliBin    = $venvDir.'/bin/acd_cli';

if (!is_dir($venvDir)) {
    runStep('Creating acd_cli virtualenv', sprintf('%s -m venv %s', escapeshellarg($python), escapeshellarg($venvDir)));
}

if (!is_file($pythonBin)) {
    if ($dryRun) {
        return;
    }
    logmsg('[WARN] acd_cli virtualenv missing python binary after creation');
    return;
}

// Some Debian builds create venvs without pip preinstalled. Bootstrap it if missing.
if (!is_file($pipBin)) {
    runStep('Bootstrapping pip in acd_cli virtualenv', sprintf('%s -m ensurepip --upgrade', escapeshellarg($pythonBin)));
}

// If pip is still unavailable, fail soft with a clear log and skip.
if (!is_file($pipBin)) {
    if (!$dryRun) {
        logmsg('[ERR] acd_cli virtualenv missing pip; ensure python3-venv is installed and try rerunning update');
    }
    return;
}

runStep('Upgrading acd_cli virtualenv tooling', sprintf('%s -m pip install --upgrade pip setuptools wheel', escapeshellarg($pythonBin)));
runStep('Installing acd_cli in virtualenv', sprintf('%s -m pip install --upgrade git+https://github.com/yadayada/acd_cli.git', escapeshellarg($pythonBin)));

if (is_file($cliBin)) {
    runStep('Linking acd_cli CLI', sprintf('ln -sf %s %s', escapeshellarg($cliBin), escapeshellarg('/usr/local/bin/acd_cli')));
} elseif (!$dryRun) {
    logmsg('[WARN] acd_cli binary not found in virtualenv after install');
}
