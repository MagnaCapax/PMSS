<?php
/**
 * Shared helpers for PMSS user lifecycle operations.
 *
 * Centralises username validation and audit logging for add/suspend/unsuspend/
 * terminate flows so operators have a single place to review user changes.
 */

if (!defined('PMSS_USER_LOG_TEXT')) {
    define('PMSS_USER_LOG_TEXT', '/var/log/pmss/users.log');
}

if (!defined('PMSS_USER_LOG_JSON')) {
    define('PMSS_USER_LOG_JSON', '/var/log/pmss/users.jsonl');
}

/**
 * Validate a username according to PMSS constraints.
 *
 * - Only ASCII letters and digits allowed.
 * - Must start with a letter.
 * - Maximum length 8 characters.
 */
function pmssUsernameIsValid(string $username): bool
{
    if ($username === '') {
        return false;
    }
    return (bool) preg_match('/^[A-Za-z][A-Za-z0-9]{0,7}$/', $username);
}

/**
 * Back-compat alias for validation helper used in legacy scripts/tests.
 */
function pmssValidateUsername(string $username): bool
{
    return pmssUsernameIsValid($username);
}

/**
 * Build a shared context payload for user lifecycle audit logging.
 */
function pmssUserBaseContext(string $action, string $phase, string $username, array $extra = array()): array
{
    $operatorUid  = function_exists('posix_geteuid') ? @posix_geteuid() : null;
    $operatorName = getenv('SUDO_USER') ?: getenv('USER') ?: null;
    if ($operatorUid !== null && function_exists('posix_getpwuid')) {
        $pw = @posix_getpwuid($operatorUid);
        if (is_array($pw) && isset($pw['name']) && $pw['name'] !== '') {
            $operatorName = $operatorName ?: $pw['name'];
        }
    }

    $base = array(
        'event'              => 'user_'.$action,
        'action'             => $action,
        'phase'              => $phase,
        'username'           => $username,
        'operator_user'      => $operatorName,
        'operator_uid'       => $operatorUid,
        'host'               => function_exists('gethostname') ? @gethostname() : php_uname('n'),
        'ssh_connection'     => getenv('SSH_CONNECTION') ?: null,
        'ssh_client'         => getenv('SSH_CLIENT') ?: null,
        'sudo_user'          => getenv('SUDO_USER') ?: null,
        'pmss_correlation_id'=> getenv('PMSS_CORRELATION_ID') ?: null,
        'argv'               => isset($GLOBALS['argv']) ? $GLOBALS['argv'] : array(),
    );

    return array_merge($base, $extra);
}

/**
 * Write a user lifecycle audit record to both JSON and human-readable logs.
 */
function pmssUserWriteLogs(array $payload): void
{
    if (!isset($payload['ts'])) {
        $payload['ts'] = date('c');
    }

    // JSON line for programmatic consumption
    $line = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($line !== false) {
        @file_put_contents(PMSS_USER_LOG_JSON, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    // Text line for operators
    $ts      = date('[Y-m-d H:i:s] ');
    $status  = $payload['status'] ?? 'INFO';
    $action  = $payload['action'] ?? 'unknown';
    $phase   = $payload['phase'] ?? 'unknown';
    $user    = $payload['username'] ?? '';
    $message = isset($payload['message']) ? ' msg='.$payload['message'] : '';
    $step    = isset($payload['step']) ? ' step='.$payload['step'] : '';

    $text = $ts.'user='.$user.' action='.$action.' phase='.$phase.' status='.$status.$step.$message;
    @file_put_contents(PMSS_USER_LOG_TEXT, $text.PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Execute a shell command for a user lifecycle step and log the outcome.
 */
function pmssUserLifecycleStep(string $action, string $username, string $step, string $command, bool $dryRun): int
{
    $started = microtime(true);
    if ($dryRun) {
        pmssUserWriteLogs(
            pmssUserBaseContext(
                $action,
                $step,
                $username,
                array(
                    'step'     => $step,
                    'command'  => $command,
                    'rc'       => 0,
                    'duration' => 0.0,
                    'dry_run'  => true,
                    'status'   => 'SKIP',
                )
            )
        );
        echo "[DRY-RUN][{$action}] {$step}: {$command}\n";
        return 0;
    }

    $rc = 0;
    @passthru($command, $rc);
    $duration = microtime(true) - $started;

    pmssUserWriteLogs(
        pmssUserBaseContext(
            $action,
            $step,
            $username,
            array(
                'step'     => $step,
                'command'  => $command,
                'rc'       => $rc,
                'duration' => round($duration, 4),
                'dry_run'  => false,
                'status'   => $rc === 0 ? 'OK' : 'ERR',
            )
        )
    );

    return $rc;
}

/**
 * Terminate-specific wrappers used by scripts/terminateUser.php.
 */
function pmssUserTerminateContext($username, $phase, array $extra = array())
{
    return pmssUserBaseContext('terminate', $phase, $username, $extra);
}

function pmssUserTerminateLog(array $payload)
{
    pmssUserWriteLogs($payload);
}

function pmssUserTerminateStep($username, $step, $command, $dryRun)
{
    return pmssUserLifecycleStep('terminate', $username, $step, $command, $dryRun);
}

