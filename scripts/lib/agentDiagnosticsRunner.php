<?php
/**
 * Command and file helpers for agent diagnostics collection.
 *
 * Keeps shelling and JSON decoding in one place so the collector can stay
 * focused on section assembly.
 *
 * @license GPL-3.0-only
 */
declare(strict_types=1);

require_once __DIR__.'/runtime.php';

/** Resolve the repository root used for internal script calls. */
function pmssAgentDiagnosticsScriptRoot(): string
{
    $override = getenv('PMSS_AGENT_DIAGNOSTICS_SCRIPT_ROOT');
    if (is_string($override) && $override !== '') {
        return rtrim($override, '/');
    }
    return dirname(__DIR__, 2);
}

/** Resolve an internal PMSS script path through the diagnostics script root. */
function pmssAgentDiagnosticsScriptPath(string $relativePath): string
{
    return pmssAgentDiagnosticsScriptRoot().'/'.ltrim($relativePath, '/');
}

/** Read an optional diagnostics input file, honoring a test override path. */
function pmssAgentDiagnosticsReadFile(string $envKey, string $defaultPath): string
{
    $path = getenv($envKey);
    if (!is_string($path) || $path === '') {
        $path = $defaultPath;
    }
    $contents = @file_get_contents($path);
    return is_string($contents) ? $contents : '';
}

/** Execute a shell command and capture stdout, stderr, and rc. */
function pmssAgentDiagnosticsCapture(string $command): array
{
    return pmssCommandCapture($command, 0, false, 'Failed to launch command');
}

/** Execute a repository PHP script relative to the diagnostics script root. */
function pmssAgentDiagnosticsPhpScript(string $relativePath, array $arguments = []): array
{
    $scriptPath = pmssAgentDiagnosticsScriptPath($relativePath);
    if (!is_file($scriptPath) || !is_readable($scriptPath)) {
        return [
            'rc' => 1,
            'stdout' => '',
            'stderr' => 'Diagnostics script missing or unreadable: '.$relativePath,
        ];
    }

    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($scriptPath);
    foreach ($arguments as $argument) {
        $command .= ' '.escapeshellarg((string) $argument);
    }
    return pmssAgentDiagnosticsCapture($command);
}

/** Decode JSON output from a command result or return an error payload. */
function pmssAgentDiagnosticsDecodeJson(array $result, string $label): array
{
    if ((int) $result['rc'] !== 0) {
        return [
            'error' => $label.' failed',
            'rc' => (int) $result['rc'],
            'stderr' => trim((string) $result['stderr']),
        ];
    }

    $decoded = json_decode((string) $result['stdout'], true);
    if (!is_array($decoded)) {
        return [
            'error' => $label.' returned invalid JSON',
            'rc' => (int) $result['rc'],
            'stdout' => trim((string) $result['stdout']),
        ];
    }

    return $decoded;
}

/** Split command stdout into trimmed non-empty lines. */
function pmssAgentDiagnosticsOutputLines(array $result): array
{
    if ((int) $result['rc'] !== 0) {
        return [];
    }
    $lines = preg_split('/\r?\n/', trim((string) $result['stdout']));
    return array_values(array_filter(is_array($lines) ? $lines : [], 'strlen'));
}

/** Return trimmed command stdout or a fallback when nothing useful was emitted. */
function pmssAgentDiagnosticsCommandText(string $command, string $fallback = ''): string
{
    $result = pmssAgentDiagnosticsCapture($command);
    $text = trim((string) $result['stdout']);
    return $text !== '' ? $text : $fallback;
}

/** Execute a repository PHP script and decode its JSON payload. */
function pmssAgentDiagnosticsPhpJson(string $relativePath, array $arguments, string $label): array
{
    return pmssAgentDiagnosticsDecodeJson(pmssAgentDiagnosticsPhpScript($relativePath, $arguments), $label);
}
