<?php
/**
 * Shared helpers for managing Python 3 virtual environments in app installers.
 *
 * PHP 7.3 compatible; no typed properties or union types.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../logging.php';

/**
 * Ensure a Python 3 venv exists at $venvDir with pip usable and upgraded.
 *
 * Returns ['python' => <path>, 'pip' => <path>] on success or an empty array on failure.
 * Uses runStep() so PMSS_DRY_RUN is honoured; failures are logged and soft.
 */
function pmssPythonVenvEnsure(string $venvDir, string $label, ?callable $logger = null): array
{
    $log = $logger ?: 'logMessage';

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

    // Determine if pip is actually importable, not just if a script exists.
    exec(escapeshellarg($pythonBin).' -m pip --version 1>/dev/null 2>&1', $out, $rc);
    $hasPip = $rc === 0;
    if (!$hasPip) {
        runStep('Bootstrapping pip in '.$label.' virtualenv', sprintf('%s -m ensurepip --upgrade --default-pip', escapeshellarg($pythonBin)));
        // Emit minimal debug context to help diagnose odd hosts.
        runStep('Debug '.$label.' ensurepip context', sprintf("%s -c 'import sys,ensurepip; print(sys.version); print(getattr(ensurepip,\"__file__\",\"n/a\"))'", escapeshellarg($pythonBin)));
        exec(escapeshellarg($pythonBin).' -m pip --version 1>/dev/null 2>&1', $out, $rc);
        $hasPip = $rc === 0;
    }

    if (!$hasPip) {
        $log('[ERR] '.$label.' virtualenv missing pip after ensurepip; ensure python3-venv is installed and rerun update');
        // List venv bin dir to aid debugging.
        @runStep('Debug '.$label.' venv bin listing', sprintf('ls -la %s || true', escapeshellarg(dirname($pythonBin))));
        return [];
    }

    runStep('Upgrading '.$label.' virtualenv tooling', sprintf('%s -m pip install --upgrade pip setuptools wheel', escapeshellarg($pythonBin)));

    return ['python' => $pythonBin, 'pip' => $pipBin];
}
