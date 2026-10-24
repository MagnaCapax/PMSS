<?php
/** Quota command result normalization and maintenance reporting. @license GPL-3.0-only */
require_once __DIR__.'/../runtime.php';

/**
 * Run one quota maintenance command and return exit-code-aware output.
 *
 * @param callable|null $runner Test seam; receives the shell command and returns
 *                              rc/stdout/stderr keys like pmssCommandCapture().
 *
 * @return array{ok:bool,rc:int,output:string}
 */
function pmssQuotaCommandRun(string $command, ?callable $runner = null): array
{
    // Reject malformed command bytes before trim() or a supplied runner sees them.
    $result = strpos($command, "\0") === false && ($command = trim($command)) !== ''
        ? ($runner ?? 'pmssCommandCapture')($command)
        : null;
    if (!is_array($result)) {
        return ['ok' => false, 'rc' => 1, 'output' => ''];
    }

    $rcRaw = $result['rc'] ?? 1;
    $rc = is_numeric($rcRaw) ? (int) $rcRaw : 1;
    $stdout = is_string($result['stdout'] ?? null) ? $result['stdout'] : '';
    $stderr = is_string($result['stderr'] ?? null) ? $result['stderr'] : '';

    return ['ok' => $rc === 0, 'rc' => $rc, 'output' => $stdout.$stderr];
}

/**
 * Run a quotaFix maintenance command while preserving legacy command output.
 *
 * `quotaon -p` returns the count of enabled quota types, so verification queries
 * can return non-zero for a healthy state and must not be logged as failures.
 *
 * @param callable|null $runner Test seam; receives the shell command and returns
 *                              rc/stdout/stderr keys like pmssCommandCapture().
 * @param callable|null $logger Test seam for logMessage().
 *
 * @return array{ok:bool,rc:int,output:string}
 */
function pmssQuotaFixRunCommand(string $description, string $command, bool $critical, int &$exitCode, bool $isQuery = false, ?callable $runner = null, ?callable $logger = null): array
{
    $log = $logger ?: 'logMessage';
    $log($description);
    $result = pmssQuotaCommandRun($command, $runner);
    if ($result['output'] !== '') {
        echo $result['output'];
    }
    if (!$result['ok'] && !$isQuery) {
        $log('[quotaFix] WARNING: command failed (rc='.$result['rc'].'): '.$command);
    }
    if (!$result['ok'] && $critical) {
        $exitCode = 1;
    }

    return $result;
}
