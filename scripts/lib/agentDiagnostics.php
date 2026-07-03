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

/** Execute a repository PHP script relative to the diagnostics script root. */
function pmssAgentDiagnosticsPhpScript(string $relativePath, array $arguments = [], int $timeoutSec = PMSS_AGENT_DIAGNOSTICS_COMMAND_TIMEOUT_DEFAULT): array
{
    $relativePath = trim($relativePath);
    if ($relativePath === '' || $relativePath[0] === '/' || !pmssPathSegmentsAreSafe($relativePath, true, false)) {
        return ['rc' => 1, 'stdout' => '', 'stderr' => 'Diagnostics script path unsafe: '.$relativePath];
    }

    $scriptRoot = pmssResolvePathFromEnv('PMSS_AGENT_DIAGNOSTICS_SCRIPT_ROOT', dirname(__DIR__, 2));
    if (!pmssPathSegmentsAreSafe($scriptRoot, false, false, true, true)) {
        return ['rc' => 1, 'stdout' => '', 'stderr' => 'Diagnostics script root unsafe: '.$scriptRoot];
    }

    $scriptPath = $scriptRoot.'/'.$relativePath;
    if (!is_file($scriptPath) || !is_readable($scriptPath)) return ['rc' => 1, 'stdout' => '', 'stderr' => 'Diagnostics script missing or unreadable: '.$relativePath];
    // Use 'php' from $PATH instead of PHP_BINARY — consistent with update.php (GH#589).
    $command = escapeshellarg('php').' '.escapeshellarg($scriptPath);
    foreach ($arguments as $argument) {
        $command .= ' '.escapeshellarg((string) $argument);
    }
    return pmssCommandCapture($command, max(1, $timeoutSec), false, 'Failed to launch command');
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
        'cgroup' => [
            // Read-only cgroup-hierarchy + per-user-isolation posture probe (cgroup-v2 viability canary, GH #648).
            'mode' => ['type' => 'command', 'command' => 'stat -fc %T /sys/fs/cgroup 2>/dev/null', 'format' => 'text', 'fallback' => 'unknown'],
            'hierarchy_cmdline' => ['type' => 'command', 'command' => "grep -o 'systemd.unified_cgroup_hierarchy=[01]' /proc/cmdline 2>/dev/null", 'format' => 'text', 'fallback' => 'unset'],
            'proc_hidepid' => ['type' => 'command', 'command' => 'grep -w hidepid /proc/mounts 2>/dev/null', 'format' => 'lines'],
            // Functional hidepid=2 tenant-isolation probe (not just the mount option): as a sample user,
            // confirm /proc PID visibility is restricted to own procs and root PID 1 is hidden. Output
            // "root_pids=N sample_user=U user_pids=M pid1=hidden|readable"; ENFORCED when M<<N AND pid1=hidden.
            // pid1=readable (or M~=N) means hidepid is off/bypassed (the gid=proc hole class) — a privacy regression.
            'hidepid_functional' => ['type' => 'command', 'command' => 'r=$(ls -d /proc/[0-9]* 2>/dev/null | wc -l); u=$(php /scripts/listUsers.php 2>/dev/null | head -1); if [ -n "$u" ]; then up=$(su -s /bin/bash "$u" -c "ls -d /proc/[0-9]* 2>/dev/null | wc -l"); p1=$(su -s /bin/bash "$u" -c "cat /proc/1/comm >/dev/null 2>&1 && echo readable || echo hidden"); echo "root_pids=$r sample_user=$u user_pids=$up pid1=$p1"; else echo no-users; fi', 'format' => 'text', 'fallback' => 'unknown'],
            'user_managers_active' => ['type' => 'command', 'command' => "systemctl list-units 'user@*.service' --state=active --no-legend --no-pager 2>/dev/null | wc -l", 'format' => 'int'],
            'user_managers_failed' => ['type' => 'command', 'command' => "systemctl list-units 'user@*.service' --state=failed --no-legend --no-pager 2>/dev/null | wc -l", 'format' => 'int'],
            'user_io_stat_sample' => ['type' => 'command', 'command' => 'cat /sys/fs/cgroup/user.slice/user-*.slice/io.stat 2>/dev/null | head -2', 'format' => 'lines'],
            'user_cpu_pressure_sample' => ['type' => 'command', 'command' => 'cat /sys/fs/cgroup/user.slice/user-*.slice/cpu.pressure 2>/dev/null | head -2', 'format' => 'lines'],
        ],
        'system_test' => ['type' => 'php', 'path' => 'scripts/util/systemTest.php', 'args' => ['--json'], 'format' => 'json'],
        'users' => [
            'list' => ['type' => 'php', 'path' => 'scripts/listUsers.php', 'format' => 'lines'],
            'consistency' => ['type' => 'php', 'path' => 'scripts/util/checkUsers.php', 'args' => ['--json'], 'format' => 'json'],
        ],
        'resources' => ['type' => 'php', 'path' => 'scripts/util/userResourcesList.php', 'args' => ['--full', '--json'], 'format' => 'json'],
        'traffic' => ['type' => 'php', 'path' => 'scripts/showTraffic.php', 'args' => ['--json'], 'format' => 'json'],
    ];
    if ($user !== '') {
        $userArg = escapeshellarg($user);
        $sections['user_settings'] = ['type' => 'php', 'path' => 'scripts/userSetting.php', 'args' => ['view', $user], 'format' => 'json'];
        $sections['user_processes'] = ['type' => 'command', 'command' => 'pgrep -u '.$userArg.' -a 2>/dev/null', 'format' => 'lines'];
        foreach ([
            'user_identity' => 'id '.$userArg,
            'user_quota' => 'quota -u '.$userArg.' 2>/dev/null',
            'user_disk' => 'du -sBG '.escapeshellarg('/home/'.$user).' 2>/dev/null',
        ] as $name => $command) {
            $sections[$name] = ['type' => 'command', 'command' => $command, 'wrap' => 'raw'];
        }
    }

    return $sections;
}

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
    if (is_string($timeout) && ctype_digit($timeout)) {
        $timeout = (int) $timeout;
    }
    if (!is_int($timeout) || $timeout < 1) {
        return PMSS_AGENT_DIAGNOSTICS_COMMAND_TIMEOUT_DEFAULT;
    }
    return min($timeout, PMSS_AGENT_DIAGNOSTICS_COMMAND_TIMEOUT_DEFAULT);
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
        $lines = (int) ($result['rc'] ?? 1) === 0 ? preg_split('/\r?\n/', $stdout) : [];
        return pmssNonEmptyStrings(is_array($lines) ? $lines : []);
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
    if ($format === 'int') {
        return (int) ($stdout !== '' ? $stdout : (string) ($spec['fallback'] ?? '0'));
    }
    $value = $stdout !== '' ? $stdout : (string) ($spec['fallback'] ?? '');
    return isset($spec['wrap']) ? [(string) $spec['wrap'] => $value] : $value;
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
        $output .= (pmssJsonEncodePretty($section) ?? '')."\n";
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
        $selection = pmssManagedUsersSelectFromCommand(
            pmssResolvePathFromEnv('PMSS_AGENT_DIAGNOSTICS_SCRIPT_ROOT', dirname(__DIR__, 2)).'/scripts/listUsers.php',
            $user,
            ['strictInput' => true]
        );
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
