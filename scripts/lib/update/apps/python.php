<?php
/**
 * FlexGet + gdrivefs installer using a dedicated Python 3 virtual environment.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/pythonVenv.php';

$venvDir   = '/opt/flexget';
$cliBin    = $venvDir.'/bin/flexget';

pmssPythonVenvInstallCli($venvDir, 'FlexGet', [
    ['Installing gdrivefs in FlexGet venv', 'gdrivefs'],
    ['Installing FlexGet dependencies', "pyopenssl ndg-httpsclient cryptography funcsigs 'chardet==3.0.3' 'certifi==2017.4.17'"],
    ['Installing FlexGet', 'flexget'],
    ['Installing youtube-dl for FlexGet', 'youtube_dl'],
], $cliBin, '/usr/local/bin/flexget', '[WARN] Skipping FlexGet install: python3 missing from PATH', '[WARN] FlexGet binary missing after install', 'logmsg');
