<?php
/** Recursive diagnostic acquisition and result projection. @license GPL-3.0-only */
declare(strict_types=1);

/** Build the stable human label used in JSON error payloads. */
function pmssAgentDiagnosticsSpecLabel(array $spec): string
{
    $path = trim((string) ($spec['path'] ?? ''));
    if ($path === '') return trim((string) ($spec['command'] ?? 'diagnostics command'));
    $args = pmssNonEmptyStrings(array_map('strval', (array) ($spec['args'] ?? [])));
    return $args === [] ? basename($path) : basename($path).' '.implode(' ', $args);
}

/** Resolve the bounded timeout for a diagnostics command spec. */
function pmssAgentDiagnosticsSpecTimeout(array $spec): int
{
    $timeout = $spec['timeout'] ?? PMSS_AGENT_DIAGNOSTICS_COMMAND_TIMEOUT_DEFAULT;
    return (is_int($timeout) || (is_string($timeout) && ctype_digit($timeout))) && (int) $timeout > 0
        ? min((int) $timeout, PMSS_AGENT_DIAGNOSTICS_COMMAND_TIMEOUT_DEFAULT)
        : PMSS_AGENT_DIAGNOSTICS_COMMAND_TIMEOUT_DEFAULT;
}

/** Collect one diagnostics spec node through a single recursive path. */
function pmssAgentDiagnosticsSpecCollect(array $spec)
{
    if (!isset($spec['type'])) {
        $sections = [];
        foreach ($spec as $name => $childSpec) {
            $sections[$name] = pmssAgentDiagnosticsSpecCollect($childSpec);
        }
        return $sections;
    }
    if ((string) ($spec['type'] ?? '') === 'file') {
        $value = @file_get_contents(pmssResolvePathFromEnv((string) $spec['env'], (string) $spec['path']));
        $value = is_string($value) ? $value : '';
        return isset($spec['wrap']) ? [(string) $spec['wrap'] => $value] : $value;
    }

    $timeoutSec = pmssAgentDiagnosticsSpecTimeout($spec);
    $result = ((string) ($spec['type'] ?? '') === 'php')
        ? pmssAgentDiagnosticsPhpScript((string) $spec['path'], (array) ($spec['args'] ?? []), $timeoutSec)
        : pmssCommandCapture((string) $spec['command'], $timeoutSec, false, 'Failed to launch command');
    $stdout = trim((string) ($result['stdout'] ?? ''));
    $format = (string) ($spec['format'] ?? 'text');

    if ($format === 'lines') {
        return pmssNonEmptyStrings((int) ($result['rc'] ?? 1) === 0 ? pmssTextLines($stdout) : []);
    }
    if ($format === 'json') {
        if ((int) ($result['rc'] ?? 1) !== 0) {
            return ['error' => pmssAgentDiagnosticsSpecLabel($spec).' failed', 'rc' => (int) $result['rc'], 'stderr' => trim((string) ($result['stderr'] ?? ''))];
        }
        $decoded = pmssJsonDecodeAssoc((string) ($result['stdout'] ?? ''));
        return $decoded !== null
            ? $decoded
            : ['error' => pmssAgentDiagnosticsSpecLabel($spec).' returned invalid JSON', 'rc' => (int) $result['rc'], 'stdout' => $stdout];
    }
    $value = $stdout !== '' ? $stdout : (string) ($spec['fallback'] ?? '');
    if ($format === 'int') return (int) $value;
    return isset($spec['wrap']) ? [(string) $spec['wrap'] => $value] : $value;
}

/** Assemble the full diagnostics payload. */
function pmssAgentDiagnosticsCollect(string $user = ''): array
{
    return [
        'timestamp' => date('c'),
        'hostname' => gethostname() ?: '',
        'version' => trim((string) @file_get_contents(pmssResolvePathFromEnv('PMSS_AGENT_DIAGNOSTICS_VERSION_PATH', '/etc/seedbox/config/version'))),
        'version_completed' => trim((string) @file_get_contents(pmssResolvePathFromEnv('PMSS_AGENT_DIAGNOSTICS_VERSION_COMPLETED_PATH', '/etc/seedbox/config/version.completed'))),
        'user' => $user !== '' ? $user : null,
        'sections' => pmssAgentDiagnosticsSpecCollect(pmssAgentDiagnosticsSectionSpecs($user)),
    ];
}
