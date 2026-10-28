<?php
/** Checked PHP-script execution for diagnostic snapshots. @license GPL-3.0-only */
declare(strict_types=1);

/** Execute a repository PHP script relative to the diagnostics script root. */
function pmssAgentDiagnosticsPhpScript(string $relativePath, array $arguments = [], int $timeoutSec = PMSS_AGENT_DIAGNOSTICS_COMMAND_TIMEOUT_DEFAULT): array
{
    // trim() removes edge NULs; reject the original path before it can name a script.
    if (pmssFilesystemPathHasNulByte($relativePath)) {
        return ['rc' => 1, 'stdout' => '', 'stderr' => 'Diagnostics script path unsafe: NUL byte'];
    }
    $relativePath = trim($relativePath);
    if ($relativePath === '' || $relativePath[0] === '/' || !pmssPathSegmentsAreSafe($relativePath, true, false)) {
        return ['rc' => 1, 'stdout' => '', 'stderr' => 'Diagnostics script path unsafe: '.$relativePath];
    }

    // Resolve the script root from this nested module, not the repository root.
    // On production the repository parent is "/", which the path-safety guard rejects.
    // Probe paths are relative to /scripts; explicit repository-root overrides retain their fallback.
    $scriptRoot = pmssResolvePathFromEnv('PMSS_AGENT_DIAGNOSTICS_SCRIPT_ROOT', dirname(__DIR__, 2));
    if (!pmssPathSegmentsAreSafe($scriptRoot, false, false, true, true)) {
        return ['rc' => 1, 'stdout' => '', 'stderr' => 'Diagnostics script root unsafe: '.$scriptRoot];
    }

    $scriptPath = $scriptRoot.'/'.$relativePath;
    $reportedPath = $relativePath;
    $scriptRootOverride = getenv('PMSS_AGENT_DIAGNOSTICS_SCRIPT_ROOT');
    $repositoryScriptPath = $scriptRoot.'/scripts/'.$relativePath;
    if (is_string($scriptRootOverride) && $scriptRootOverride !== '' && !is_file($scriptPath)
        && strpos($relativePath, 'scripts/') !== 0 && (is_file($repositoryScriptPath) || is_dir($scriptRoot.'/scripts'))) {
        $reportedPath = 'scripts/'.$relativePath;
        if (is_file($repositoryScriptPath)) $scriptPath = $repositoryScriptPath;
    }
    if (!is_file($scriptPath) || !is_readable($scriptPath)) return ['rc' => 1, 'stdout' => '', 'stderr' => 'Diagnostics script missing or unreadable: '.$reportedPath];
    // Retain argv scalar conversion, but fail softly before shell quoting rejects NULs.
    $arguments = array_map('strval', $arguments);
    foreach ($arguments as $argument) {
        if (pmssFilesystemPathHasNulByte($argument)) {
            return ['rc' => 1, 'stdout' => '', 'stderr' => 'Diagnostics script argument unsafe: NUL byte'];
        }
    }
    // Use 'php' from $PATH instead of PHP_BINARY — consistent with update.php (GH#589).
    return pmssCommandCapture(pmssCommandArgvShellQuote(array_merge(['php', $scriptPath], $arguments)), max(1, $timeoutSec), false, 'Failed to launch command');
}
