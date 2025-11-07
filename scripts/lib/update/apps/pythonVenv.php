<?php
/**
 * Shared helpers for managing Python 3 virtual environments in app installers.
 *
 * PHP 7.3 compatible; no typed properties or union types.
 */

require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../logging.php';

if (!function_exists('pmssPythonVenvEnsure')) {
    /**
     * Ensure a Python 3 venv exists at $venvDir with pip usable and upgraded.
     *
     * Returns ['python' => <path>, 'pip' => <path>] on success or an empty array on failure.
     * Uses runStep() so PMSS_DRY_RUN is honoured; failures are logged and soft.
     */
    function pmssPythonVenvEnsure(string $venvDir, string $label, ?callable $logger = null): array
    {
        $log = pmssSelectLogger($logger);

        $python = trim((string) @shell_exec('command -v python3 2>/dev/null'));
        if ($python === '') {
            $log('[WARN] Skipping '.$label.' setup: python3 missing');
            return [];
        }

        if (!is_dir($venvDir)) {
            runStep('Creating '.$label.' virtualenv', sprintf('%s -m venv %s', escapeshellarg($python), escapeshellarg($venvDir)));
        }

        $pythonBin = rtrim($venvDir, '/').'/bin/python';
        $pipBin    = rtrim($venvDir, '/').'/bin/pip';

        if (!is_file($pythonBin)) {
            $log('[WARN] '.$label.' virtualenv missing python binary after creation');
            return [];
        }

        if (!is_file($pipBin)) {
            runStep('Bootstrapping pip in '.$label.' virtualenv', sprintf('%s -m ensurepip --upgrade', escapeshellarg($pythonBin)));
        }

        if (!is_file($pipBin)) {
            $log('[ERR] '.$label.' virtualenv missing pip; ensure python3-venv is installed and rerun update');
            return [];
        }

        runStep('Upgrading '.$label.' virtualenv tooling', sprintf('%s -m pip install --upgrade pip setuptools wheel', escapeshellarg($pythonBin)));

        return ['python' => $pythonBin, 'pip' => $pipBin];
    }
}

