<?php
/**
 * FlexGet + gdrivefs installer using a dedicated Python 3 virtual environment.
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

$python = trim((string) @shell_exec('command -v python3 2>/dev/null'));
if ($python === '') {
    $logger('[WARN] Skipping FlexGet install: python3 missing from PATH');
    return;
}

$venvDir   = '/opt/flexget';
$cliBin    = $venvDir.'/bin/flexget';

$venv = pmssPythonVenvEnsure($venvDir, 'FlexGet', $logger);
if (empty($venv)) {
    return;
}

runStep('Installing gdrivefs in FlexGet venv', sprintf('%s -m pip install --upgrade gdrivefs', escapeshellarg($venv['python'])));
runStep('Installing FlexGet dependencies', sprintf("%s -m pip install --upgrade pyopenssl ndg-httpsclient cryptography funcsigs 'chardet==3.0.3' 'certifi==2017.4.17'", escapeshellarg($venv['python'])));
runStep('Installing FlexGet', sprintf('%s -m pip install --upgrade flexget', escapeshellarg($venv['python'])));
runStep('Installing youtube-dl for FlexGet', sprintf('%s -m pip install --upgrade youtube_dl', escapeshellarg($venv['python'])));

if (is_file($cliBin)) {
    if (!is_link('/usr/local/bin/flexget') || readlink('/usr/local/bin/flexget') !== $cliBin) {
        runStep('Linking FlexGet CLI', sprintf('ln -sf %s %s', escapeshellarg($cliBin), escapeshellarg('/usr/local/bin/flexget')));
    }
} elseif (getenv('PMSS_DRY_RUN') !== '1') {
    $logger('[WARN] FlexGet binary missing after install');
}
