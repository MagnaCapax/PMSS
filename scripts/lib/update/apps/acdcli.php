<?php
/**
 * acd_cli helper installation.
 *
 * Installs or refreshes acd_cli via pip so legacy automation keeps working
 * until the helper is migrated to a dedicated virtualenv.
 */

require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../logging.php';
require_once __DIR__.'/pythonVenv.php';

$dryRun = getenv('PMSS_DRY_RUN') === '1';
// #TODO Pin acd_cli to a specific commit/tag to avoid unbounded upgrades.

$venvDir = '/opt/acd_cli';
$cliBin  = $venvDir.'/bin/acd_cli';

$venv = pmssPythonVenvEnsure($venvDir, 'acd_cli', 'logmsg');
if (empty($venv)) {
    return;
}

runStep('Installing acd_cli in virtualenv', sprintf('%s -m pip install --upgrade git+https://github.com/yadayada/acd_cli.git', escapeshellarg($venv['python'])));

if (is_file($cliBin)) {
    runStep('Linking acd_cli CLI', sprintf('ln -sf %s %s', escapeshellarg($cliBin), escapeshellarg('/usr/local/bin/acd_cli')));
} elseif (!$dryRun) {
    logmsg('[WARN] acd_cli binary not found in virtualenv after install');
}
