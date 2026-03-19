<?php
/**
 * FlexGet + gdrivefs installer using a dedicated Python 3 virtual environment.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/pythonVenv.php';

if (trim((string) @shell_exec('command -v python3 2>/dev/null')) === '') {
    logmsg('[WARN] Skipping FlexGet install: python3 missing from PATH');
    return;
}

$venvDir   = '/opt/flexget';
$cliBin    = $venvDir.'/bin/flexget';

$venv = pmssPythonVenvEnsure($venvDir, 'FlexGet', 'logmsg');
if (empty($venv)) {
    return;
}

$venvPython = escapeshellarg($venv['python']);
foreach ([
    ['Installing gdrivefs in FlexGet venv', 'gdrivefs'],
    ['Installing FlexGet dependencies', "pyopenssl ndg-httpsclient cryptography funcsigs 'chardet==3.0.3' 'certifi==2017.4.17'"],
    ['Installing FlexGet', 'flexget'],
    ['Installing youtube-dl for FlexGet', 'youtube_dl'],
] as $installStep) {
    runStep($installStep[0], sprintf('%s -m pip install --upgrade %s', $venvPython, $installStep[1]));
}

if (!is_file($cliBin)) {
    if (getenv('PMSS_DRY_RUN') !== '1') logmsg('[WARN] FlexGet binary missing after install');
    return;
}

if (!is_link('/usr/local/bin/flexget') || readlink('/usr/local/bin/flexget') !== $cliBin) {
    runStep('Linking FlexGet CLI', sprintf('ln -sf %s %s', escapeshellarg($cliBin), escapeshellarg('/usr/local/bin/flexget')));
}
