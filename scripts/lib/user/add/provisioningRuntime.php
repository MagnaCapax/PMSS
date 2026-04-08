<?php
/**
 * addUser provisioning runtime helpers.
 *
 * These helpers are intentionally small wrappers around existing PMSS runtime
 * utilities so `scripts/addUser.php` can stay concise without changing its
 * external behaviour.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../../runtime.php';

/**
 * Resolve the addUser provisioning log path with a hermetic test override.
 */
function pmssAddUserProvisioningLogPath(): string
{
    return pmssResolvePathFromEnv('PMSS_ADDUSER_LOG_PATH', '/var/log/pmss/addUser.log');
}

/**
 * Read the latest structured addUser summary stored in this process.
 *
 * @return array<string,mixed>|null
 */
function pmssAddUserProvisionSummaryGet(): ?array
{
    $summary = $GLOBALS['PMSS_ADDUSER_PROVISION_SUMMARY'] ?? null;
    return is_array($summary) ? $summary : null;
}

/**
 * Initialise runtime knobs used by addUser provisioning runs.
 *
 * - Removes time limits in CLI runs (best-effort).
 * - Continues execution if the SSH session dies (best-effort).
 */
function pmssAddUserRuntimeInit(): void
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }
    @ignore_user_abort(true);

    /**
     * Best-effort detachment from a dying SSH session in non-interactive runs.
     *
     * When automation launches addUser.php without a TTY, a backend timeout can
     * close the SSH channel and deliver SIGHUP/SIGPIPE. Ignoring those signals
     * helps the provisioning continue while logs are written to disk.
     */
    if (!pmssStreamIsTty(STDIN) && !pmssStreamIsTty(STDOUT) && !pmssStreamIsTty(STDERR)) {
        if (function_exists('posix_setsid')) {
            @posix_setsid();
        }
        if (function_exists('pcntl_signal')) {
            if (defined('SIGHUP')) {
                @pcntl_signal(SIGHUP, SIG_IGN);
            }
            if (defined('SIGPIPE')) {
                @pcntl_signal(SIGPIPE, SIG_IGN);
            }
        }
    }
}

/**
 * Append a message to the provisioning log and console for traceability.
 *
 * @param string $message Human-readable status printed for the operator.
 */
function logProvisionMessage(string $message): void
{
    global $user;
    pmssLogAppendTimestampedLine(pmssAddUserProvisioningLogPath(), $message, 'Y-m-d H:i:s', " ({$user['name']}): ");
    echo $message.PHP_EOL;
    if (function_exists('pmssUserLog')) {
        pmssUserLog($user['name'], $message);
    }
    pmssUserWriteLogs(
        pmssUserBaseContext(
            'add',
            'log',
            $user['name'],
            array(
                'status'  => 'INFO',
                'message' => $message,
            )
        )
    );
}

/**
 * Emit a fatal addUser summary and exit with the legacy non-zero status.
 */
function pmssAddUserFatalExit(string $status, string $detail, string $message, array $extraSummary = array()): void
{
    logProvisionMessage('FATAL: '.$detail);
    finalizeProvision($status, $message, 1, $extraSummary);
    exit(1);
}

/**
 * Run a provisioning step that must succeed or abort the addUser flow.
 */
function pmssAddUserRunRequiredProvisionStep(
    string $description,
    string $command,
    string $failureDetail,
    string $failureCode,
    ?string $logCommand = null
): void
{
    if (runProvisionStep($description, $command, $logCommand) === 0) {
        return;
    }

    pmssAddUserFatalExit('FAIL', $failureDetail, $failureCode);
}

/**
 * Run a shell command and log whether it succeeded without aborting.
 * The 'continue on failure' behavior is intentional to allow as many
 * provisioning steps as possible to complete.
 *
 * @param string $description Operator-facing label describing the action.
 * @param string $command     Full shell command executed through runCommand().
 * @param string|null $logCommand Optional redacted command for logs.
 *
 * @return int Return code bubbled up from the child command.
 */
function runProvisionStep(string $description, string $command, ?string $logCommand = null): int
{
    global $user, $provisionStats;

    $logCommand = $logCommand ?? $command;
    $logger = 'logProvisionMessage';
    if ($logCommand !== $command) {
        $logger = function (string $message) use ($command, $logCommand): void {
            logProvisionMessage(str_replace($command, $logCommand, $message));
        };
    }

    $startedAt = microtime(true);
    $result = runCommand($command, false, $logger);
    $duration = round(microtime(true) - $startedAt, 4);
    $provisionStats['steps']++;
    if ($result !== 0) {
        $provisionStats['err']++;
        $provisionStats['last_error'] = $description . ' rc=' . $result;
        logProvisionMessage($description . ' failed (rc=' . $result . ', duration=' . $duration . 's)');
    } else {
        $provisionStats['ok']++;
        logProvisionMessage($description . ' completed (duration=' . $duration . 's)');
    }
    pmssUserWriteLogs(
        pmssUserBaseContext(
            'add',
            'step',
            $user['name'],
            array(
                'status'   => $result === 0 ? 'OK' : 'ERR',
                'step'     => $description,
                'command'  => $logCommand,
                'rc'       => $result,
                'duration' => $duration,
            )
        )
    );
    return $result;
}

/**
 * Emit a single-line status marker and structured summary for operators.
 *
 * @param string $status  SUCCESS|FAIL|ERROR marker.
 * @param string $message Human-readable context (kept short for grep).
 * @param int    $exitCode Intended exit code for the summary.
 * @param array  $extraSummary Optional machine-readable reason/detail fields.
 */
function finalizeProvision(string $status, string $message, int $exitCode, array $extraSummary = array()): void
{
    global $user, $provisionStart, $provisionStats, $provisionFinalized;
    if ($provisionFinalized) {
        return;
    }
    $provisionFinalized = true;

    $duration = round(microtime(true) - $provisionStart, 3);
    $summary = sprintf(
        '###ADDUSER:%s user=%s duration=%ss steps_ok=%d steps_err=%d message=%s',
        $status,
        $user['name'],
        $duration,
        $provisionStats['ok'],
        $provisionStats['err'],
        $message
    );
    logProvisionMessage($summary);

    $jsonSummary = array_merge(
        array(
            'event' => 'adduser_summary',
            'status' => $status,
            'success' => $status === 'SUCCESS' && $exitCode === 0,
            'user' => $user['name'],
            'message' => $message,
            'exit_code' => $exitCode,
            'duration' => $duration,
            'steps_ok' => $provisionStats['ok'],
            'steps_err' => $provisionStats['err'],
            'last_error' => $provisionStats['last_error'],
        ),
        $extraSummary
    );
    $GLOBALS['PMSS_ADDUSER_PROVISION_SUMMARY'] = $jsonSummary;
    $jsonEncoded = json_encode($jsonSummary, JSON_UNESCAPED_SLASHES);
    if ($jsonEncoded !== false) {
        logProvisionMessage('###ADDUSER_JSON:'.$jsonEncoded);
    }

    pmssUserWriteLogs(
        pmssUserBaseContext(
            'add',
            'summary',
            $user['name'],
            array(
                'status'      => $status === 'SUCCESS' ? 'OK' : 'ERR',
                'result'      => $status,
                'message'     => $message,
                'duration'    => $duration,
                'exit_code'   => $exitCode,
                'steps_ok'    => $provisionStats['ok'],
                'steps_err'   => $provisionStats['err'],
                'last_error'  => $provisionStats['last_error'],
            )
        )
    );
}
