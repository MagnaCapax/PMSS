<?php
/**
 * Shared helpers for managing Python 3 virtual environments in app installers.
 *
 * Keep this helper compatible with the repository PHP 7.3 runtime baseline.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../logging.php';

/**
 * Ensure a Python 3 venv exists at $venvDir with pip usable and upgraded.
 *
 * Returns the venv python path on success or an empty string on failure.
 * Uses runStep() so PMSS_DRY_RUN is honoured; failures are logged and soft.
 */
function pmssPythonVenvEnsure(
    string $venvDir,
    string $label,
    ?callable $logger = null,
    ?string $missingPythonMessage = null
): string
{
    $log = $logger ?: 'logMessage';
    $python = pmssCommandPath('python3');
    if ($python === '') {
        $log($missingPythonMessage !== null ? $missingPythonMessage : '[WARN] Skipping '.$label.' setup: python3 missing');
        return '';
    }
    if (!is_dir($venvDir)) {
        runStep('Creating '.$label.' virtualenv', pmssBuildCommand($python, ['-m', 'venv', $venvDir]));
    }

    $pythonBin = rtrim($venvDir, '/').'/bin/python';
    if (!is_file($pythonBin)) {
        $log('[WARN] '.$label.' virtualenv missing python binary after creation');
        return '';
    }

    // Determine if pip is actually importable, not just if a script exists.
    exec(pmssBuildCommand($pythonBin, ['-m', 'pip', '--version']).' 1>/dev/null 2>&1', $out, $rc);
    $hasPip = $rc === 0;
    if (!$hasPip) {
        runStep('Bootstrapping pip in '.$label.' virtualenv', pmssBuildCommand($pythonBin, ['-m', 'ensurepip', '--upgrade', '--default-pip']));
        // Emit minimal debug context to help diagnose odd hosts.
        runStep('Debug '.$label.' ensurepip context', pmssBuildCommand($pythonBin, ['-c', 'import sys,ensurepip; print(sys.version); print(getattr(ensurepip,"__file__","n/a"))']));
        exec(pmssBuildCommand($pythonBin, ['-m', 'pip', '--version']).' 1>/dev/null 2>&1', $out, $rc);
        $hasPip = $rc === 0;
    }

    if (!$hasPip) {
        $log('[ERR] '.$label.' virtualenv missing pip after ensurepip; ensure python3-venv is installed and rerun update');
        // List venv bin dir to aid debugging.
        @runStep('Debug '.$label.' venv bin listing', pmssBuildCommand('ls', ['-la', dirname($pythonBin)]).' || true');
        return '';
    }

    runStep('Upgrading '.$label.' virtualenv tooling', pmssBuildCommand($pythonBin, ['-m', 'pip', 'install', '--upgrade', 'pip', 'setuptools', 'wheel']));
    return $pythonBin;
}

/** Install Python packages into an ensured venv and link the exposed CLI. */
function pmssPythonVenvInstallCli(
    string $venvDir,
    string $label,
    array $installSteps,
    string $cliBin,
    string $linkPath,
    string $missingPythonMessage,
    string $missingCliMessage,
    ?callable $logger = null
): void {
    $log = $logger ?: 'logmsg';
    $venvPython = pmssPythonVenvEnsure($venvDir, $label, $log, $missingPythonMessage);
    if ($venvPython === '') {
        return;
    }
    $pipInstallPrefix = pmssBuildCommand($venvPython, ['-m', 'pip', 'install', '--upgrade']);
    foreach ($installSteps as $installStep) {
        runStep($installStep[0], $pipInstallPrefix.' '.$installStep[1]);
    }
    if (!is_file($cliBin)) {
        if (!pmssEnvFlagEnabled('PMSS_DRY_RUN')) $log($missingCliMessage);
        return;
    }
    if (!is_link($linkPath) || readlink($linkPath) !== $cliBin) {
        runStep('Linking '.$label.' CLI', pmssBuildCommand('ln', ['-sf', $cliBin, $linkPath]));
    }
}
