<?php
/** Scheduled backup customer command and JSON protocol. @license GPL-3.0-only */

/** Bound customer diagnostics before they reach either logging channel. */
function pmssScheduledConfigBackupMessageSafe(string $message): string { return pmssLogExcerpt(pmssLogLineCompact($message), 300); }

/** Keep outcome field order and allow caller metadata to retain override precedence. */
function pmssScheduledConfigBackupOutcome(string $status, string $message, array $extra = []): array
{
    return array_merge(['status' => $status, 'message' => pmssScheduledConfigBackupMessageSafe($message)], $extra);
}

/** The customer program returns one JSON line and a matching success exit status. */
function pmssScheduledConfigBackupCustomerPhpCode(): string
{
    return <<<'PHP'
require_once $argv[1];
$home = $argv[2] ?? '';
$action = $argv[3] ?? '';
$keep = (int) ($argv[4] ?? 7);
$result = array('ok' => false, 'message' => 'Unknown scheduled backup action.', 'bytes' => 0, 'path' => '');
if ($action === 'create') {
    $result = pmssCustomerBackupFileCreate($home);
} elseif ($action === 'prune') {
    $result = pmssCustomerBackupRetentionPrune($home, $keep);
}
$encoded = json_encode($result, JSON_UNESCAPED_SLASHES);
echo (is_string($encoded) ? $encoded : '{"ok":false,"message":"Could not encode scheduled backup result.","bytes":0,"path":""}').PHP_EOL;
exit(!empty($result['ok']) ? 0 : 1);
PHP;
}

/** Validate identity, local include containment and action before quoting the customer command. */
function pmssScheduledConfigBackupCommandBuild(string $user, string $home, string $action, int $retention): string
{
    if (!UserValidator::isValidUsername($user)) return '';
    $include = rtrim($home, '/').'/www/scriptsInc.php';
    if (!pmssRegularFilePathIsReadable($include) || !pmssPathWithinResolvedRoot($include, $home)
        || !in_array($action, ['create', 'prune'], true)) return '';
    $php = defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '' && is_executable(PHP_BINARY)
        ? PHP_BINARY : (is_executable('/usr/bin/php') ? '/usr/bin/php' : 'php');

    $inner = pmssCommandArgvShellQuote([
        $php,
        '-r',
        pmssScheduledConfigBackupCustomerPhpCode(),
        '--',
        $include,
        $home,
        $action,
        (string) $retention,
    ]);
    return pmssBuildUserShellCommand($user, $inner, '/bin/bash');
}

/** Decode only the last nonempty stdout line; a failed process cannot report success. */
function pmssScheduledConfigBackupCustomerResult(array $process): array
{
    $rc = is_numeric($process['rc'] ?? null) ? (int) $process['rc'] : 1;
    $jsonLine = '';
    foreach (pmssConfigTextLines((string) ($process['stdout'] ?? ''), []) as $line) {
        $jsonLine = $line;
    }

    $payload = $jsonLine !== '' ? pmssJsonDecodeAssoc($jsonLine) : null;
    // Customer JSON containers cannot supply scalar results or authorize retention.
    foreach (['ok', 'message', 'bytes', 'path', 'keptCount', 'deletedCount'] as $field) {
        if (isset($payload[$field]) && !is_scalar($payload[$field])) {
            $payload = null;
            break;
        }
    }
    if (!is_array($payload)) {
        $stderr = pmssScheduledConfigBackupMessageSafe((string) ($process['stderr'] ?? ''));
        return ['ok' => false, 'message' => 'scheduled backup helper returned invalid output'.($stderr !== '' ? ': '.$stderr : ''), 'bytes' => 0, 'path' => ''];
    }
    if ($rc !== 0) $payload['ok'] = false;

    return [
        'ok' => !empty($payload['ok']),
        'message' => pmssScheduledConfigBackupMessageSafe((string) ($payload['message'] ?? '')),
        'bytes' => max(0, (int) ($payload['bytes'] ?? 0)),
        'path' => (string) ($payload['path'] ?? ''),
        'keptCount' => max(0, (int) ($payload['keptCount'] ?? 0)),
        'deletedCount' => max(0, (int) ($payload['deletedCount'] ?? 0)),
    ];
}

/** Build, capture and decode through one command path; tests may supply the capture. */
function pmssScheduledConfigBackupCustomerAction(string $user, string $home, string $action, int $retention, ?callable $runner = null): array
{
    $command = pmssScheduledConfigBackupCommandBuild($user, $home, $action, $retention);
    if ($command === '') return ['ok' => false, 'message' => 'scheduled backup helper command could not be built', 'bytes' => 0, 'path' => ''];
    $process = $runner === null
        ? pmssCommandPipedCapture(pmssCommandBashInvocation($command), $command,
            pmssCommandTimeoutSeconds($command), 1048576, false,
            'scheduled config backup command launch failed', 1, true)
        : $runner($command, ['user' => $user, 'home' => $home, 'action' => $action, 'retention' => $retention]);
    return pmssScheduledConfigBackupCustomerResult(is_array($process) ? $process : []);
}
