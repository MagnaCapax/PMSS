<?php
/** PMSS diagnostic snapshot collector and CLI renderer.
 * Aggregates existing PMSS probes into one read-only report.
 * @license GPL-3.0-only
 */
declare(strict_types=1);

require_once __DIR__.'/cli/optionParser.php';
require_once __DIR__.'/runtime.php';
require_once __DIR__.'/userLifecycle.php';

const PMSS_AGENT_DIAGNOSTICS_COMMAND_TIMEOUT_DEFAULT = 60;

foreach (['command', 'catalog', 'collect'] as $module) require_once __DIR__.'/agentDiagnostics/'.$module.'.php';

/** Render a readable text view when JSON output is not requested. */
function pmssAgentDiagnosticsRenderText(array $payload): string
{
    $output = "PMSS Agent Diagnostics\n";
    foreach (['timestamp', 'hostname', 'version', 'version_completed'] as $field) {
        $output .= $field.': '.($payload[$field] ?? '')."\n";
    }
    $output .= 'user: '.(($payload['user'] ?? null) === null ? '-' : $payload['user'])."\n";
    foreach (($payload['sections'] ?? []) as $name => $section) {
        $output .= "\n== {$name} ==\n";
        $output .= (is_string($section) ? rtrim($section) : (pmssJsonEncodePretty($section) ?? ''))."\n";
    }
    return $output;
}

/** CLI entrypoint for the agent diagnostics utility. */
function pmssAgentDiagnosticsMain(array $argv): int
{
    $usage = pmssCliHelpUsageOptions([
        'agentDiagnostics.php [--json] [--pretty] [--user USERNAME]', 'agentDiagnostics.php [--help]',
    ], [
        ['--json', 'Emit JSON output.'],
        ['--pretty', 'Pretty-print JSON output.'],
        ['--user USER', 'Include per-user diagnostics.'],
    ], 16, [], false, ['-h, --help', 'Show this help text.']);
    if (($parsed = pmssParseCliTokensOrHelp($argv, $usage, ['user'])) === null) return 0;

    if (!pmssTestModeEnabled()) requireRoot();

    $user = trim((string) pmssCliOption($parsed, 'user', 'u', ''));
    if ($user !== '') {
        $listUsersResult = pmssAgentDiagnosticsPhpScript('listUsers.php');
        $listUsersExitCode = (int) ($listUsersResult['rc'] ?? 1);
        if ($listUsersExitCode !== 0) {
            return pmssCliReturnWithStderr("Error: listUsers.php failed; aborting.\n", $listUsersExitCode);
        }
        $listUsersLines = pmssTextLines(trim((string) ($listUsersResult['stdout'] ?? '')));
        if (!is_array($listUsersLines) || pmssManagedUsersOutputHasDiagnostics($listUsersLines)) {
            return pmssCliReturnWithStderr("Error: listUsers.php failed; aborting.\n");
        }
        $selection = pmssManagedUsersSelectFromList($listUsersLines, $user, ['strictInput' => true]);
        if ((int) $selection['exitCode'] !== 0) return (int) $selection['exitCode'];
        $user = (string) $selection['username'];
    }

    $payload = pmssAgentDiagnosticsCollect($user);
    if (pmssCliOptionPresent($parsed, 'json', 'j')) {
        $flags = JSON_UNESCAPED_SLASHES;
        if (pmssCliOptionPresent($parsed, 'pretty', 'p')) $flags |= JSON_PRETTY_PRINT;
        return pmssJsonEmitPayload($payload, 'Failed to encode agent diagnostics JSON.', $flags);
    }

    echo pmssAgentDiagnosticsRenderText($payload);
    return 0;
}
