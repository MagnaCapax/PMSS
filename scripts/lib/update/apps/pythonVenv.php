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

/** Validate common venv setup inputs before probing Python or creating files. */
function pmssPythonVenvBaseInputsAreSafe(string $venvDir, string $label, callable $log): bool
{
    if (trim($label) === '' || preg_match('/[\x00-\x1F\x7F]/', $label) === 1) {
        $log('[WARN] Skipping Python virtualenv setup: unsafe label');
        return false;
    }
    if ($venvDir === '' || strpos($venvDir, "\0") !== false) {
        $log('[WARN] Skipping '.$label.' virtualenv setup: unsafe venv path');
        return false;
    }

    return true;
}

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
    if (!pmssPythonVenvBaseInputsAreSafe($venvDir, $label, $log)) {
        return '';
    }

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
    if (!pmssPythonVenvBaseInputsAreSafe($venvDir, $label, $log)) {
        return;
    }
    if ($cliBin === '' || $linkPath === '' || strpos($cliBin, "\0") !== false || strpos($linkPath, "\0") !== false) {
        $log('[WARN] Skipping '.$label.' install: unsafe CLI path');
        return;
    }

    $normalizedInstallSteps = [];
    foreach ($installSteps as $installStep) {
        if (!is_array($installStep)
            || !isset($installStep[0], $installStep[1])
            || !is_scalar($installStep[0])
            || !is_scalar($installStep[1])
        ) {
            $log('[WARN] Skipping '.$label.' install: unsafe install step');
            return;
        }

        $description = trim((string) $installStep[0]);
        $args = trim((string) $installStep[1]);
        if ($description === ''
            || $args === ''
            || preg_match('/[\x00-\x1F\x7F]/', $description.$args) === 1
            || preg_match('/[;&|`$<>\\\\]/', $args) === 1
        ) {
            $log('[WARN] Skipping '.$label.' install: unsafe install step');
            return;
        }

        $normalizedInstallSteps[] = [$description, $args];
    }

    $venvPython = pmssPythonVenvEnsure($venvDir, $label, $log, $missingPythonMessage);
    if ($venvPython === '') {
        return;
    }
    $pipInstallPrefix = pmssBuildCommand($venvPython, ['-m', 'pip', 'install', '--upgrade']);
    foreach ($normalizedInstallSteps as $installStep) {
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
