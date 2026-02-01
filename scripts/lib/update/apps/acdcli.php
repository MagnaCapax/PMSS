<?php
/**
 * acd_cli helper installation.
 *
 * Installs or refreshes acd_cli via pip so legacy automation keeps working
 * until the helper is migrated to a dedicated virtualenv.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../logging.php';
require_once __DIR__.'/pythonVenv.php';

// Pinned upstream version to avoid unbounded upgrades. (GH #129)
$acdCliPinnedTag    = '0.3.2';
$acdCliPinnedCommit = 'af929608f6279fca56e6c9ac6c48bd76d4ff1dcb';

$venvDir = '/opt/acd_cli';
$cliBin  = $venvDir.'/bin/acd_cli';

$venv = pmssPythonVenvEnsure($venvDir, 'acd_cli', 'logmsg');
if (empty($venv)) {
    return;
}

$forceUpdate = getenv('PMSS_FORCE_ACDCLI_UPDATE') === '1';
if (pmssPythonVenvHasPackage($venv['python'], 'acdcli') && !$forceUpdate) {
    logmsg('[SKIP] acd_cli already installed; set PMSS_FORCE_ACDCLI_UPDATE=1 to refresh');
} else {
    $source = sprintf('git+https://github.com/yadayada/acd_cli.git@%s', $acdCliPinnedCommit);
    runStep(
        sprintf('Installing acd_cli %s in virtualenv', $acdCliPinnedTag),
        sprintf(
            '%s -m pip install --upgrade %s',
            escapeshellarg($venv['python']),
            escapeshellarg($source)
        )
    );
}

if (is_file($cliBin)) {
    runStep('Linking acd_cli CLI', sprintf('ln -sf %s %s', escapeshellarg($cliBin), escapeshellarg('/usr/local/bin/acd_cli')));
} elseif (getenv('PMSS_DRY_RUN') !== '1') {
    logmsg('[WARN] acd_cli binary not found in virtualenv after install');
}
