<?php
/**
 * PMSS diagnostic snapshot collector and CLI renderer.
 *
 * Aggregates existing PMSS probes into one read-only report so operators can
 * gather a quick server and optional user snapshot from a single entry point.
 *
 * @license GPL-3.0-only
 */
declare(strict_types=1);

require_once __DIR__.'/cli/optionParser.php';
require_once __DIR__.'/runtime.php';
require_once __DIR__.'/userLifecycle.php';
require_once __DIR__.'/agentDiagnosticsRunner.php';

/** Return CLI usage text for the diagnostics wrapper. */
function pmssAgentDiagnosticsUsage(): string
{
    return "Usage:\n"
        ."  agentDiagnostics.php [--json] [--pretty] [--user USERNAME]\n"
        ."  agentDiagnostics.php [--help]\n\n"
        ."Options:\n"
        ."  --json          Emit JSON output.\n"
        ."  --pretty        Pretty-print JSON output.\n"
        ."  --user USER     Include per-user diagnostics.\n"
        ."  -h, --help      Show this help text.\n";
}

/** Collect always-on storage and service sections. */
function pmssAgentDiagnosticsCollectBaseSections(): array
{
    $storage = [
        'df' => pmssAgentDiagnosticsOutputLines(pmssAgentDiagnosticsCapture('df -h')),
        'df_inodes' => pmssAgentDiagnosticsOutputLines(pmssAgentDiagnosticsCapture('df -i')),
    ];
    foreach ([
        'mdstat' => ['PMSS_AGENT_DIAGNOSTICS_MDSTAT_PATH', '/proc/mdstat'],
        'fstab' => ['PMSS_AGENT_DIAGNOSTICS_FSTAB_PATH', '/etc/fstab'],
    ] as $key => $fileSpec) {
        $storage[$key] = pmssAgentDiagnosticsReadFile($fileSpec[0], $fileSpec[1]);
    }

    $services = [];
    foreach ([
        'nginx' => ['systemctl is-active nginx 2>/dev/null', 'unknown'],
        'proftpd' => ['systemctl is-active proftpd 2>/dev/null', 'unknown'],
        'cron' => ['systemctl is-active cron 2>/dev/null', 'unknown'],
        'ssh' => ['systemctl is-active ssh 2>/dev/null', 'unknown'],
    ] as $key => $commandSpec) {
        $services[$key] = pmssAgentDiagnosticsCommandText($commandSpec[0], $commandSpec[1]);
    }
    foreach ([
        'rtorrent_count' => 'pgrep -cx rtorrent 2>/dev/null',
        'lighttpd_count' => 'pgrep -cx lighttpd 2>/dev/null',
    ] as $key => $command) {
        $services[$key] = (int) pmssAgentDiagnosticsCommandText($command);
    }

    return [
        'motd' => ['raw' => pmssAgentDiagnosticsReadFile('PMSS_AGENT_DIAGNOSTICS_MOTD_PATH', '/etc/motd')],
        'storage' => $storage,
        'services' => $services,
        'system_test' => pmssAgentDiagnosticsPhpJson('scripts/util/systemTest.php', ['--json'], 'systemTest.php --json'),
        'users' => [
            'list' => pmssAgentDiagnosticsOutputLines(pmssAgentDiagnosticsPhpScript('scripts/listUsers.php')),
            'consistency' => pmssAgentDiagnosticsPhpJson('scripts/util/checkUsers.php', ['--json'], 'checkUsers.php --json'),
        ],
        'resources' => pmssAgentDiagnosticsPhpJson('scripts/util/userResourcesList.php', ['--full', '--json'], 'userResourcesList.php --full --json'),
        'traffic' => pmssAgentDiagnosticsPhpJson('scripts/showTraffic.php', ['--json'], 'showTraffic.php --json'),
    ];
}

/** Collect optional per-user diagnostics after shared validation. */
function pmssAgentDiagnosticsCollectUserSections(string $user): array
{
    $sections = [
        'user_settings' => pmssAgentDiagnosticsPhpJson('scripts/userSetting.php', ['view', $user], 'userSetting.php view'),
        'user_processes' => pmssAgentDiagnosticsOutputLines(
            pmssAgentDiagnosticsCapture('pgrep -u '.escapeshellarg($user).' -a 2>/dev/null')
        ),
    ];

    foreach ([
        'user_identity' => 'id '.escapeshellarg($user),
        'user_quota' => 'quota -u '.escapeshellarg($user).' 2>/dev/null',
        'user_disk' => 'du -sBG '.escapeshellarg('/home/'.$user).' 2>/dev/null',
    ] as $key => $command) {
        $sections[$key] = ['raw' => pmssAgentDiagnosticsCommandText($command)];
    }

    return $sections;
}

/** Assemble the full diagnostics payload. */
function pmssAgentDiagnosticsCollect(string $user = ''): array
{
    $sections = pmssAgentDiagnosticsCollectBaseSections();
    if ($user !== '') {
        $sections = array_merge($sections, pmssAgentDiagnosticsCollectUserSections($user));
    }
    return [
        'timestamp' => date('c'),
        'hostname' => gethostname() ?: '',
        'version' => trim(pmssAgentDiagnosticsReadFile('PMSS_AGENT_DIAGNOSTICS_VERSION_PATH', '/etc/seedbox/config/version')),
        'user' => $user !== '' ? $user : null,
        'sections' => $sections,
    ];
}

/** Render a readable text view when JSON output is not requested. */
function pmssAgentDiagnosticsRenderText(array $payload): string
{
    $output = "PMSS Agent Diagnostics\n";
    foreach (['timestamp', 'hostname', 'version'] as $field) {
        $output .= $field.': '.($payload[$field] ?? '')."\n";
    }
    $output .= 'user: '.(($payload['user'] ?? null) === null ? '-' : $payload['user'])."\n";
    foreach (($payload['sections'] ?? []) as $name => $section) {
        $output .= "\n== {$name} ==\n";
        if (is_string($section)) {
            $output .= rtrim($section)."\n";
            continue;
        }
        $output .= json_encode($section, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }
    return $output;
}

/** CLI entrypoint for the agent diagnostics utility. */
function pmssAgentDiagnosticsMain(array $argv): int
{
    $parsed = pmssParseCliTokens($argv, ['user']);
    if (pmssCliOption($parsed, 'help', 'h', false) !== false) {
        echo pmssAgentDiagnosticsUsage();
        return 0;
    }

    if (getenv('PMSS_TEST_MODE') !== '1') {
        requireRoot();
    }

    $user = trim((string) pmssCliOption($parsed, 'user', 'u', ''));
    if ($user !== '') {
        $selection = pmssManagedUsersSelectFromCommand(
            pmssAgentDiagnosticsScriptPath('scripts/listUsers.php'),
            $user,
            ['strictInput' => true]
        );
        if ((int) $selection['exitCode'] !== 0) {
            return (int) $selection['exitCode'];
        }
        $user = (string) $selection['username'];
    }

    $payload = pmssAgentDiagnosticsCollect($user);
    if (pmssCliOption($parsed, 'json', 'j', false) !== false) {
        $flags = JSON_UNESCAPED_SLASHES;
        if (pmssCliOption($parsed, 'pretty', 'p', false) !== false) {
            $flags |= JSON_PRETTY_PRINT;
        }
        echo json_encode($payload, $flags).PHP_EOL;
        return 0;
    }

    echo pmssAgentDiagnosticsRenderText($payload);
    return 0;
}
