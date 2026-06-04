<?php
/**
 * Shared helpers for PMSS user lifecycle operations.
 *
 * Composes user identity, selection, watchdog, and audit helpers for legacy
 * add/suspend/unsuspend/terminate flows.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/user/log.php';
require_once __DIR__.'/runtime.php';
require_once __DIR__.'/user/identity.php';
require_once __DIR__.'/user/selection.php';
require_once __DIR__.'/user/watchdog.php';
require_once __DIR__.'/user/userFilesystem.php';
require_once __DIR__.'/user/userConfigStore.php';

if (!defined('PMSS_USER_LOG_TEXT')) {
    define('PMSS_USER_LOG_TEXT', '/var/log/pmss/users.log');
}

if (!defined('PMSS_USER_LOG_JSON')) {
    define('PMSS_USER_LOG_JSON', '/var/log/pmss/users.jsonl');
}

/**
 * Normalize a CLI username argument, log validation failure, and abort on error.
 *
 * Shared by operator-facing scripts so they all enforce the same trust boundary
 * while keeping each script's public error text intact.
 */
function pmssRequireCliUsername(string $rawUsername, string $action, string $errorFormat, string $logMessage = 'Rejected username due to validation failure'): string
{
    $normalized = pmssUsernameNormalizeIfValid($rawUsername);
    if ($normalized !== null) {
        return $normalized;
    }

    $normalized = pmssNormalizeUsername($rawUsername);
    pmssUserLifecycleContextLogStatusMessage($action, 'validate', $normalized, 'ERR', $logMessage);

    die(sprintf($errorFormat, $normalized));
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
    $payload = array_merge(array('status' => $status, 'message' => $message), $extra);
    pmssUserLifecycleContextLog($action, $phase, $username, $payload);
}

/**
 * Write the common lifecycle info payload for actions scoped to a home dir.
 */
function pmssUserLifecycleContextLogHomeInfo(string $action, string $phase, string $username, string $homeDir): void
{
    pmssUserLifecycleContextLog($action, $phase, $username, array('status' => 'INFO', 'home_dir' => $homeDir));
}

/**
 * Convert arbitrary log fields into a single-line text-safe representation.
 */
function pmssUserLifecycleFormatTextField($value): string
{
    if ($value === null) {
        return '';
    }

    if (is_bool($value)) {
        $text = $value ? 'true' : 'false';
    } elseif (is_scalar($value)) {
        $text = (string) $value;
    } elseif (is_object($value) && method_exists($value, '__toString')) {
        $text = (string) $value;
    } else {
        $text = gettype($value);
    }

    $normalized = str_replace(array("\r", "\n", "\t", "\0"), ' ', $text);
    $normalized = preg_replace('/[[:cntrl:]]+/', ' ', $normalized);
    $normalized = preg_replace('/\s+/', ' ', trim((string) $normalized));

    return is_string($normalized) ? $normalized : '';
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
    $status  = pmssUserLifecycleFormatTextField($payload['status'] ?? 'INFO');
    $action  = pmssUserLifecycleFormatTextField($payload['action'] ?? 'unknown');
    $phase   = pmssUserLifecycleFormatTextField($payload['phase'] ?? 'unknown');
    $user    = pmssUserLifecycleFormatTextField($payload['username'] ?? '');
    $message = isset($payload['message']) ? ' msg='.pmssUserLifecycleFormatTextField($payload['message']) : '';
    $step    = isset($payload['step']) ? ' step='.pmssUserLifecycleFormatTextField($payload['step']) : '';

    $text = 'user='.$user.' action='.$action.' phase='.$phase.' status='.$status.$step.$message;
    pmssLogAppendTimestampedLine(PMSS_USER_LOG_TEXT, $text);
}

/**
 * Execute a shell command for a user lifecycle step and log the outcome.
 */
function pmssUserLifecycleStep(string $action, string $username, string $step, string $command, bool $dryRun): int
{
    $started = microtime(true);
    $rc = 0;
    $duration = 0.0;
    $status = $dryRun ? 'SKIP' : 'OK';
    $dryRunMessage = $dryRun ? "[DRY-RUN][{$action}] {$step}: {$command}\n" : '';
    if (!$dryRun) {
        @passthru($command, $rc);
        $duration = microtime(true) - $started;
        $status = $rc === 0 ? 'OK' : 'ERR';
    }

    pmssUserLifecycleContextLog($action, $step, $username, array(
        'step'     => $step,
        'command'  => $command,
        'rc'       => $rc,
        'duration' => round($duration, 4),
        'dry_run'  => $dryRun,
        'status'   => $status,
    ));

    if ($dryRunMessage !== '') {
        echo $dryRunMessage;
    }

    return $rc;
}

/**
 * Run an ordered table of lifecycle shell steps through the shared logger.
 *
 * @param array<int,array{0:string,1:string}> $steps
 * @return array<string,int>
 */
function pmssUserLifecycleRunSteps(string $action, string $username, array $steps, bool $dryRun): array
{
    $results = array();
    foreach ($steps as $stepSpec) {
        $results[(string) $stepSpec[0]] = pmssUserLifecycleStep($action, $username, (string) $stepSpec[0], (string) $stepSpec[1], $dryRun);
    }
    return $results;
}

/** @return array<string,string> */
function pmssUserLifecycleRequireUserRoots(array $argv, string $scriptName, string $action): array
{
    $username = (string) ($argv[1] ?? '');
    if ($username === '') {
        die($scriptName." USERNAME\n");
    }
    ['username' => $username, 'homeDir' => $homeDir] = userFilesystem::requireCliUserHome(
        $username,
        $action,
        "Invalid username: %s\n",
        "User home %s missing\n"
    );

    return array(
        'username' => $username,
        'homeDir' => $homeDir,
        'activeRoot' => $homeDir.'/www',
        'disabledRoot' => $homeDir.'/www-disabled',
    );
}

/**
 * Mirror the best-effort suspended flag into the durable user config store.
 */
function pmssUserLifecycleSetSuspendedState(string $username, bool $suspended, ?callable $stateWriter = null): bool
{
    $writer = $stateWriter ?? static function (string $writerUsername, bool $writerSuspended): bool {
        static $store = null;
        if ($store === null) {
            $store = new UserConfigStore();
        }

        return $store->setSuspended($writerUsername, $writerSuspended);
    };

    return (bool) $writer($username, $suspended);
}

/**
 * Mirror the canonical `www-disabled` marker into the durable config store.
 */
function pmssUserLifecycleSyncSuspendedState(string $username, string $disabledRoot, ?callable $stateWriter = null): bool
{
    $suspended = is_dir($disabledRoot);
    pmssUserLifecycleSetSuspendedState($username, $suspended, $stateWriter);
    return $suspended;
}

/**
 * Find the newest suspended web backup that still contains user content.
 */
function pmssUserLifecycleFindSuspendedBackup(string $homeDir): ?string
{
    $candidates = glob($homeDir.'/www-suspended-*', GLOB_NOSORT);
    if (!is_array($candidates) || empty($candidates)) {
        return null;
    }
    $bestPath = null;
    $bestMtime = 0;
    $hasSuspendedContent = static function (string $candidate): bool {
        if (!is_dir($candidate)) {
            return false;
        }
        if (is_dir($candidate.'/rutorrent') || is_file($candidate.'/index.php')) {
            return true;
        }
        $entries = @scandir($candidate);
        $entries = is_array($entries) ? array_diff($entries, array('.', '..')) : array();
        if (empty($entries)) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry !== 'index.html' && $entry !== 'public') {
                return true;
            }
        }
        $publicEntries = @scandir($candidate.'/public');
        return $publicEntries === false || count(array_diff($publicEntries, array('.', '..', 'index.html'))) > 0;
    };
    foreach ($candidates as $candidate) {
        if (!$hasSuspendedContent($candidate)) {
            continue;
        }
        $mtime = @filemtime($candidate);
        $mtime = $mtime === false ? 0 : $mtime;
        if ($bestPath === null || $mtime > $bestMtime || ($mtime === $bestMtime && strcmp($candidate, $bestPath) > 0)) {
            $bestPath = $candidate;
            $bestMtime = $mtime;
        }
    }

    return $bestPath;
}

/** @param array<string,string> $restartOptions */
function pmssUserLifecycleRefreshNginxConfig(string $action, string $username, bool $dryRun, string $configStep, string $configCommand, array $restartOptions = array(), ?callable $stepRunner = null): int
{
    $runner = $stepRunner ?? 'pmssUserLifecycleStep';
    $systemctlStep = isset($restartOptions['systemctlStep']) ? (string) $restartOptions['systemctlStep'] : 'restart_nginx_systemctl';
    $systemctlCommand = isset($restartOptions['systemctlCommand']) ? (string) $restartOptions['systemctlCommand'] : 'systemctl restart nginx';
    $initStep = isset($restartOptions['initStep']) ? (string) $restartOptions['initStep'] : 'restart_nginx_init';
    $initCommand = isset($restartOptions['initCommand']) ? (string) $restartOptions['initCommand'] : '/etc/init.d/nginx restart';

    $runner($action, $username, $configStep, $configCommand, $dryRun);
    $restartRc = (int) $runner($action, $username, $systemctlStep, $systemctlCommand, $dryRun);
    if ($restartRc === 0) {
        return 0;
    }

    return (int) $runner($action, $username, $initStep, $initCommand, $dryRun);
}

/**
 * Refresh the canonical per-user nginx config and restart nginx.
 */
function pmssUserLifecycleRefreshManagedNginxConfig(string $action, string $username, bool $dryRun, ?callable $stepRunner = null): int
{
    return pmssUserLifecycleRefreshNginxConfig(
        $action,
        $username,
        $dryRun,
        'refresh_nginx_config',
        'php /scripts/util/createNginxConfig.php --user '.escapeshellarg($username),
        array(),
        $stepRunner
    );
}
