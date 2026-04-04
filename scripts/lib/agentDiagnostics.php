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

/** Execute a repository PHP script relative to the diagnostics script root. */
function pmssAgentDiagnosticsPhpScript(string $relativePath, array $arguments = []): array
{
    $scriptPath = pmssResolvePathFromEnv('PMSS_AGENT_DIAGNOSTICS_SCRIPT_ROOT', dirname(__DIR__, 2)).'/'.ltrim($relativePath, '/');
    if (!is_file($scriptPath) || !is_readable($scriptPath)) {
        return ['rc' => 1, 'stdout' => '', 'stderr' => 'Diagnostics script missing or unreadable: '.$relativePath];
    }
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($scriptPath);
    foreach ($arguments as $argument) {
        $command .= ' '.escapeshellarg((string) $argument);
    }
    return pmssCommandCapture($command, 0, false, 'Failed to launch command');
}

/** Build the stable ordered section spec for the diagnostics payload. */
function pmssAgentDiagnosticsSectionSpecs(string $user = ''): array
{
    $sections = [
        'motd' => ['type' => 'file', 'env' => 'PMSS_AGENT_DIAGNOSTICS_MOTD_PATH', 'path' => '/etc/motd', 'wrap' => 'raw'],
        'storage' => [
            'df' => ['type' => 'command', 'command' => 'df -h', 'format' => 'lines'],
            'df_inodes' => ['type' => 'command', 'command' => 'df -i', 'format' => 'lines'],
            'mdstat' => ['type' => 'file', 'env' => 'PMSS_AGENT_DIAGNOSTICS_MDSTAT_PATH', 'path' => '/proc/mdstat'],
            'fstab' => ['type' => 'file', 'env' => 'PMSS_AGENT_DIAGNOSTICS_FSTAB_PATH', 'path' => '/etc/fstab'],
        ],
        'services' => [
            'nginx' => ['type' => 'command', 'command' => 'systemctl is-active nginx 2>/dev/null', 'format' => 'text', 'fallback' => 'unknown'],
            'proftpd' => ['type' => 'command', 'command' => 'systemctl is-active proftpd 2>/dev/null', 'format' => 'text', 'fallback' => 'unknown'],
            'cron' => ['type' => 'command', 'command' => 'systemctl is-active cron 2>/dev/null', 'format' => 'text', 'fallback' => 'unknown'],
            'ssh' => ['type' => 'command', 'command' => 'systemctl is-active ssh 2>/dev/null', 'format' => 'text', 'fallback' => 'unknown'],
            'rtorrent_count' => ['type' => 'command', 'command' => 'pgrep -cx rtorrent 2>/dev/null', 'format' => 'int'],
            'lighttpd_count' => ['type' => 'command', 'command' => 'pgrep -cx lighttpd 2>/dev/null', 'format' => 'int'],
        ],
        'system_test' => ['type' => 'php', 'path' => 'scripts/util/systemTest.php', 'args' => ['--json'], 'format' => 'json', 'label' => 'systemTest.php --json'],
        'users' => [
            'list' => ['type' => 'php', 'path' => 'scripts/listUsers.php', 'format' => 'lines'],
            'consistency' => ['type' => 'php', 'path' => 'scripts/util/checkUsers.php', 'args' => ['--json'], 'format' => 'json', 'label' => 'checkUsers.php --json'],
        ],
        'resources' => ['type' => 'php', 'path' => 'scripts/util/userResourcesList.php', 'args' => ['--full', '--json'], 'format' => 'json', 'label' => 'userResourcesList.php --full --json'],
        'traffic' => ['type' => 'php', 'path' => 'scripts/showTraffic.php', 'args' => ['--json'], 'format' => 'json', 'label' => 'showTraffic.php --json'],
    ];
    if ($user !== '') {
        $userArg = escapeshellarg($user);
        $sections['user_settings'] = ['type' => 'php', 'path' => 'scripts/userSetting.php', 'args' => ['view', $user], 'format' => 'json', 'label' => 'userSetting.php view'];
        $sections['user_processes'] = ['type' => 'command', 'command' => 'pgrep -u '.$userArg.' -a 2>/dev/null', 'format' => 'lines'];
        $sections['user_identity'] = ['type' => 'command', 'command' => 'id '.$userArg, 'format' => 'wrap_raw'];
        $sections['user_quota'] = ['type' => 'command', 'command' => 'quota -u '.$userArg.' 2>/dev/null', 'format' => 'wrap_raw'];
        $sections['user_disk'] = ['type' => 'command', 'command' => 'du -sBG '.escapeshellarg('/home/'.$user).' 2>/dev/null', 'format' => 'wrap_raw'];
    }

    return $sections;
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

    $result = ((string) ($spec['type'] ?? '') === 'php')
        ? pmssAgentDiagnosticsPhpScript((string) $spec['path'], (array) ($spec['args'] ?? []))
        : pmssCommandCapture((string) $spec['command'], 0, false, 'Failed to launch command');
    $stdout = trim((string) ($result['stdout'] ?? ''));
    $format = (string) ($spec['format'] ?? 'text');

    if ($format === 'lines') {
        $lines = (int) ($result['rc'] ?? 1) === 0 ? preg_split('/\r?\n/', $stdout) : [];
        return array_values(array_filter(is_array($lines) ? $lines : [], 'strlen'));
    }
    if ($format === 'json') {
        if ((int) ($result['rc'] ?? 1) !== 0) {
            return ['error' => (string) $spec['label'].' failed', 'rc' => (int) $result['rc'], 'stderr' => trim((string) ($result['stderr'] ?? ''))];
        }
        $decoded = json_decode((string) ($result['stdout'] ?? ''), true);
        return is_array($decoded)
            ? $decoded
            : ['error' => (string) $spec['label'].' returned invalid JSON', 'rc' => (int) $result['rc'], 'stdout' => $stdout];
    }
    if ($format === 'int') {
        return (int) ($stdout !== '' ? $stdout : (string) ($spec['fallback'] ?? '0'));
    }
    if ($format === 'wrap_raw') {
        return ['raw' => $stdout];
    }
    return $stdout !== '' ? $stdout : (string) ($spec['fallback'] ?? '');
}

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

/** Assemble the full diagnostics payload. */
function pmssAgentDiagnosticsCollect(string $user = ''): array
{
    return [
        'timestamp' => date('c'),
        'hostname' => gethostname() ?: '',
        'version' => trim((string) @file_get_contents(pmssResolvePathFromEnv('PMSS_AGENT_DIAGNOSTICS_VERSION_PATH', '/etc/seedbox/config/version'))),
        'user' => $user !== '' ? $user : null,
        'sections' => pmssAgentDiagnosticsSpecCollect(pmssAgentDiagnosticsSectionSpecs($user)),
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
            pmssResolvePathFromEnv('PMSS_AGENT_DIAGNOSTICS_SCRIPT_ROOT', dirname(__DIR__, 2)).'/scripts/listUsers.php',
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
