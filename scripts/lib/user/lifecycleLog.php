<?php
/** User lifecycle audit context and JSON/text projection.
 * @license GPL-3.0-only
 */

if (!defined('PMSS_USER_LOG_TEXT')) {
    define('PMSS_USER_LOG_TEXT', '/var/log/pmss/users.log');
}

if (!defined('PMSS_USER_LOG_JSON')) {
    define('PMSS_USER_LOG_JSON', '/var/log/pmss/users.jsonl');
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
        if (($pw['name'] ?? '') !== '') {
            $operatorName = $operatorName ?: $pw['name'];
        }
    }

    return array_merge(array(
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
        'argv'               => $GLOBALS['argv'] ?? array(),
    ), $extra);
}

/**
 * Write a structured user lifecycle event from action/phase fields.
 */
function pmssUserLifecycleContextLog(string $action, string $phase, string $username, array $extra = array()): void
{
    pmssUserWriteLogs(pmssUserBaseContext($action, $phase, $username, $extra));
}

/**
 * Write a structured user lifecycle event with shared status/message fields.
 */
function pmssUserLifecycleContextLogStatusMessage(string $action, string $phase, string $username, string $status, string $message, array $extra = array()): void
{
    pmssUserLifecycleContextLog($action, $phase, $username,
        array_merge(array('status' => $status, 'message' => $message), $extra));
}

/**
 * Write the common lifecycle info payload for actions scoped to a home dir.
 */
function pmssUserLifecycleContextLogHomeInfo(string $action, string $phase, string $username, string $homeDir): void
{
    pmssUserLifecycleContextLog($action, $phase, $username, array('status' => 'INFO', 'home_dir' => $homeDir));
}

/**
 * Write a user lifecycle audit record to both JSON and human-readable logs.
 */
function pmssUserWriteLogs(array $payload): void
{
    if (!pmssUserLogAllowed()) {
        return;
    }
    if (!isset($payload['ts'])) {
        $payload['ts'] = date('c');
    }

    // JSON line for programmatic consumption
    pmssJsonLineAppend(PMSS_USER_LOG_JSON, $payload);

    // Text line for operators
    $status  = pmssLogScalarText($payload['status'] ?? 'INFO');
    $action  = pmssLogScalarText($payload['action'] ?? 'unknown');
    $phase   = pmssLogScalarText($payload['phase'] ?? 'unknown');
    $user    = pmssLogScalarText($payload['username'] ?? '');
    $message = isset($payload['message']) ? ' msg='.pmssLogScalarText($payload['message']) : '';
    $step    = isset($payload['step']) ? ' step='.pmssLogScalarText($payload['step']) : '';

    $text = 'user='.$user.' action='.$action.' phase='.$phase.' status='.$status.$step.$message;
    pmssLogAppendTimestampedLine(PMSS_USER_LOG_TEXT, $text);
}
