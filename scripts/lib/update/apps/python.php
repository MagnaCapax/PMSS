<?php
/**
 * FlexGet + gdrivefs installer using a dedicated Python 3 virtual environment.
 */

require_once __DIR__.'/packages/helpers.php';

$logger = function (string $message): void {
    if (function_exists('logmsg')) {
        logmsg($message);
    } else {
        echo $message."\n";
    }
};

$dryRun = getenv('PMSS_DRY_RUN') === '1';

$python = trim((string) @shell_exec('command -v python3 2>/dev/null'));
if ($python === '') {
    $logger('[WARN] Skipping FlexGet install: python3 missing from PATH');
    return;
}

$venvDir   = '/opt/flexget';
$pythonBin = $venvDir.'/bin/python';
$pipBin    = $venvDir.'/bin/pip';
$cliBin    = $venvDir.'/bin/flexget';

runStep('Ensuring Python tooling for FlexGet', aptCmd('install -y python3 python3-venv python3-pip python3-setuptools python3-distutils'));

if (!is_dir($venvDir)) {
    runStep('Creating FlexGet virtualenv', sprintf('%s -m venv %s', escapeshellarg($python), escapeshellarg($venvDir)));
}

if (!is_file($pythonBin)) {
    if ($dryRun) {
        return;
    }
    $logger('[WARN] FlexGet virtualenv missing python binary after creation');
    return;
}

// Bootstrap pip inside the venv when missing (some Debian setups omit it).
if (!is_file($pipBin)) {
    runStep('Bootstrapping pip in FlexGet virtualenv', sprintf('%s -m ensurepip --upgrade', escapeshellarg($pythonBin)));
}

// If pip remains unavailable, fail soft with a clear message and skip.
if (!is_file($pipBin)) {
    if (!$dryRun) {
        $logger('[ERR] FlexGet virtualenv missing pip; ensure python3-venv is installed and rerun update');
    }
    return;
}

runStep('Upgrading FlexGet virtualenv tooling', sprintf('%s -m pip install --upgrade pip setuptools wheel', escapeshellarg($pythonBin)));
runStep('Installing gdrivefs in FlexGet venv', sprintf('%s -m pip install --upgrade gdrivefs', escapeshellarg($pythonBin)));
runStep('Installing FlexGet dependencies', sprintf("%s -m pip install --upgrade pyopenssl ndg-httpsclient cryptography funcsigs 'chardet==3.0.3' 'certifi==2017.4.17'", escapeshellarg($pythonBin)));
runStep('Installing FlexGet', sprintf('%s -m pip install --upgrade flexget', escapeshellarg($pythonBin)));
runStep('Installing youtube-dl for FlexGet', sprintf('%s -m pip install --upgrade youtube_dl', escapeshellarg($pythonBin)));

if (is_file($cliBin)) {
    if (!is_link('/usr/local/bin/flexget') || readlink('/usr/local/bin/flexget') !== $cliBin) {
        runStep('Linking FlexGet CLI', sprintf('ln -sf %s %s', escapeshellarg($cliBin), escapeshellarg('/usr/local/bin/flexget')));
    }
} elseif (!$dryRun) {
    $logger('[WARN] FlexGet binary missing after install');
}
