<?php
/**
 * Shared helpers for PMSS user lifecycle operations.
 *
 * Centralises username validation and audit logging for add/suspend/unsuspend/
 * terminate flows so operators have a single place to review user changes.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/user/log.php';
require_once __DIR__.'/runtime.php';
require_once __DIR__.'/user/userFilesystem.php';
require_once __DIR__.'/user/userConfigStore.php';

if (!defined('PMSS_USER_LOG_TEXT')) {
    define('PMSS_USER_LOG_TEXT', '/var/log/pmss/users.log');
}

if (!defined('PMSS_USER_LOG_JSON')) {
    define('PMSS_USER_LOG_JSON', '/var/log/pmss/users.jsonl');
}

/**
 * Normalise a username for consistent comparisons.
 */
function pmssNormalizeUsername(string $username): string
{
    return strtolower(trim($username));
}

/**
 * Validate a username according to PMSS constraints.
 *
 * - Only lowercase ASCII letters and digits allowed.
 * - Must start with a lowercase letter.
 * - Maximum length 8 characters.
 */
function pmssUsernameIsValid(string $username): bool
{
    $normalized = pmssNormalizeUsername($username);
    return $username !== ''
        && $normalized === $username
        && (bool) preg_match('/^[a-z][a-z0-9]{0,7}$/D', $normalized);
}

/**
 * Return the reserved username list for system/service accounts.
 */
function pmssReservedUsernames(): array
{
    static $reserved = null;
    if ($reserved !== null) {
        return $reserved;
    }

    $reserved = array(
        // base-passwd/passwd.master (static UIDs 0-99)
        '_apt', 'backup', 'bin', 'daemon', 'games', 'gnats', 'irc',
        'list', 'lp', 'mail', 'man', 'news', 'nobody', 'proxy',
        'root', 'sync', 'sys', 'uucp', 'www-data',

        // base-passwd/group.master (static GIDs)
        'adm', 'audio', 'cdrom', 'dialout', 'dip', 'disk', 'fax',
        'floppy', 'kmem', 'nogroup', 'operator', 'plugdev', 'sasl',
        'shadow', 'src', 'staff', 'sudo', 'tape', 'tty', 'users',
        'utmp', 'video', 'voice',

        // base-passwd/README reserved names
        'alias', 'asterisk', 'ceph', 'ftn', 'grsec-proc', 'grsec-sock-all',
        'grsec-sock-clt', 'grsec-sock-srv', 'grsec-tpe', 'haclient',
        'hacluster', 'libvirt-qemu', 'mysql', 'netplan', 'opensrf',
        'qmail', 'qmaild', 'qmaill', 'qmailp', 'qmailq', 'qmailr',
        'qmails', 'slurm', 'tac-plus', 'vchkpw', 'vpopmail',

        // Common package-created system users
        'admin', 'apache', 'apache2', 'avahi', 'avahi-autoipd', 'bind',
        'clamav', 'chrony', 'colord', 'crontab', 'cups', 'cupsys', 'dbus', 'dcc',
        'debian-exim', 'debian-deluged', 'dhcp', 'dictd', 'dnsmasq',
        'docker', 'dovecot', 'elasticsearch', 'fetchmail', 'firebird',
        'ftp', 'fuse', 'gdm', 'geoclue', 'git', 'gnome-initial-setup',
        'haldaemon', 'hplilp', 'http', 'httpd', 'identd', 'input',
        'jwhois', 'kernoops', 'klog', 'kvm', 'landscape', 'lightdm',
        'lpadmin', 'lxd', 'maas', 'memcache', 'messagebus', 'mongodb',
        'mosquitto', 'mythtv', 'nagios', 'netdev', 'nginx', 'ntp',
        'ntpsec', 'openvpn', 'polkitd', 'postfix', 'postgres', 'powerdev',
        'proftpd', 'pulse', 'radvd', 'redis', 'render', 'rtkit',
        'saned', 'sbuild', 'scanner', 'sgx', 'slocate', 'snap_daemon',
        'speech-dispatcher', 'squid', 'ssh', 'sshd', 'ssl-cert',
        'sslwrap', 'statd', 'stunnel4', 'syslog', 'systemd-coredump',
        'systemd-network', 'systemd-resolve', 'systemd-timesync',
        'tcpdump', 'telnetd', 'tftpd', 'tomcat', 'tomcat8', 'tomcat9',
        'unbound', 'usbmux', 'uuidd', 'varnish', 'vnstat', 'whoopsie',
        'www', 'zabbix', '_znc',

        // PMSS-specific (would conflict with internal use)
        'pmss', 'seedbox', 'rtorrent', 'deluge', 'qbittorrent',
        'lighttpd', 'rutorrent', 'srvadmin', 'srvapi', 'pmcseed',
        'pmcdn', 'srvmgmt',
    );

    return $reserved;
}

/**
 * Return true when a username is reserved for system/service accounts.
 */
function pmssUsernameIsReserved(string $username): bool
{
    return in_array(pmssNormalizeUsername($username), pmssReservedUsernames(), true);
}

/**
 * Validate a username for new-user provisioning (stricter than legacy checks).
 *
 * Provisioning creates fresh system users, configures services, and writes
 * state under /home. Historically PMSS allowed 1–2 character usernames, but
 * going forward we require a minimum of 3 characters for newly created users
 * while keeping legacy operations compatible with any existing short names.
 */
function pmssUsernameIsValidForCreate(string $username): bool
{
    return pmssUsernameIsValid($username)
        && strlen($username) >= 3
        && !pmssUsernameIsReserved($username);
}

/**
 * Back-compat alias for validation helper used in legacy scripts/tests.
 */
function pmssValidateUsername(string $username): bool
{
    return pmssUsernameIsValid($username);
}

/**
 * Normalize raw CLI/user input and return the username when it is valid.
 *
 * This preserves long-standing PMSS caller behavior that accepts harmless
 * casing and whitespace differences while still enforcing the canonical
 * username policy before any account or filesystem operations occur.
 */
function pmssUsernameNormalizeIfValid(string $rawUsername): ?string
{
    $normalized = pmssNormalizeUsername($rawUsername);
    return pmssValidateUsername($normalized) ? $normalized : null;
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

/** @param array<int,string> $rawUsers @return array<int,string> */
function pmssManagedUsersNormalizeList(array $rawUsers): array
{
    $users = array();
    foreach ($rawUsers as $rawUser) {
        $trimmed = trim((string) $rawUser);
        if (!pmssValidateUsername($trimmed)) {
            continue;
        }
        $users[pmssNormalizeUsername($trimmed)] = true;
    }
    return array_keys($users);
}

/** @return array{exitCode:int,users:array<int,string>} */
function pmssListManagedUsersResult(string $command = '/scripts/listUsers.php'): array
{
    $lines = array();
    $exitCode = 0;
    exec(escapeshellarg($command), $lines, $exitCode);
    return array('exitCode' => $exitCode, 'users' => pmssManagedUsersNormalizeList($lines));
}
function pmssListManagedUsersFromResult(array $listUsersResult): ?array
{
    if ((int) ($listUsersResult['exitCode'] ?? 1) === 0) return is_array($listUsersResult['users'] ?? null) ? $listUsersResult['users'] : array();
    fwrite(STDERR, "Error: listUsers.php failed; aborting.\n");
    return null;
}
/**
 * Return trimmed, normalized, validated managed usernames from listUsers.php.
 *
 * This keeps cron and utility scripts on one parsing path while preserving the
 * long-standing listUsers.php trust boundary for tenant discovery; callers may
 * consume the returned list directly without re-trimming each raw entry.
 */
function pmssListManagedUsers(string $command = '/scripts/listUsers.php'): array
{
    return pmssListManagedUsersResult($command)['users'];
}

/** Return true when watchdogs must avoid web-facing services for the user. */
function pmssUserWebRootUnavailable(string $username, string $homeRoot = '/home'): bool
{
    $homeDir = rtrim($homeRoot, '/').'/'.$username;
    return is_dir($homeDir.'/www-disabled') || !is_dir($homeDir.'/www');
}

/** Shared watchdog helpers keep cron process handling on one path. */
function pmssUserWatchdogTerminateProcesses(string $username, array $processNames, int $signal = 9): void
{
    $signal = $signal === 15 ? 15 : 9;
    foreach ($processNames as $processName) {
        if (!is_string($processName) || $processName === '') { continue; }
        @passthru('killall -'.$signal.' -u '.escapeshellarg($username).' '.escapeshellarg($processName).' 2>/dev/null');
    }
}
function pmssUserWatchdogProcessRunning(string $username, string $processName): bool
{
    if ($processName === '') { return false; }
    $matches = array();
    $exitCode = 1;
    @exec('pgrep -u '.escapeshellarg($username).' '.escapeshellarg($processName).' 2>/dev/null', $matches, $exitCode);
    return $exitCode === 0 && $matches !== array();
}
/** Return the oldest /proc start marker for exact process-name matches. */
function pmssUserWatchdogProcessStartTime(string $username, string $processName, string $procRoot = '/proc'): ?int
{
    if ($processName === '') {
        return null;
    }

    $matches = array();
    $exitCode = 1;
    @exec('pgrep -u '.escapeshellarg($username).' -x '.escapeshellarg($processName).' 2>/dev/null', $matches, $exitCode);
    if ($exitCode !== 0 || $matches === array()) {
        return null;
    }

    $oldest = null;
    $procRoot = rtrim($procRoot, '/');
    foreach ($matches as $rawPid) {
        $pid = trim((string) $rawPid);
        if ($pid === '' || preg_match('/^[0-9]+$/', $pid) !== 1) {
            continue;
        }

        $mtime = @filemtime($procRoot.'/'.$pid);
        if (!is_int($mtime)) {
            continue;
        }
        $oldest = $oldest === null ? $mtime : min($oldest, $mtime);
    }

    return $oldest;
}
function pmssUserWatchdogStartCommand(string $username, string $serviceLabel, string $command, string $userLogMessage): void
{
    if ($command === '') { return; }
    echo "Start {$serviceLabel} for user: {$username}\n";
    passthru($command);
    pmssUserLog($username, $userLogMessage);
}
/** @param array<int,string> $processNames */
function pmssUserWatchdogRestartProcessesIf(string $username, bool $running, array $processNames, callable $restartNeeded, string $userLogMessage, int $signal = 9, ?callable $terminator = null): bool
{
    if (!$running || !$restartNeeded()) { return $running; }
    $terminator !== null ? $terminator() : pmssUserWatchdogTerminateProcesses($username, $processNames, $signal);
    pmssUserLog($username, $userLogMessage);
    return false;
}
/** @param array<int,array<string,mixed>> $serviceSpecs @param array<string,bool> $runningStates @return array<string,bool> */
function pmssUserWatchdogEnsureServices(string $username, array $serviceSpecs, array $runningStates = array()): array
{
    foreach ($serviceSpecs as $serviceSpec) {
        $processName = isset($serviceSpec['processName']) ? (string) $serviceSpec['processName'] : '';
        if ($processName === '') { continue; }
        $command = $serviceSpec['command'] ?? '';
        is_callable($command) && $command = (string) $command($username);
        $running = isset($runningStates[$processName]) ? (bool) $runningStates[$processName] : pmssUserWatchdogProcessRunning($username, $processName);
        !$running && pmssUserWatchdogStartCommand($username, (string) ($serviceSpec['serviceLabel'] ?? $processName), (string) $command, (string) ($serviceSpec['userLogMessage'] ?? ($processName.' start requested')));
        $runningStates[$processName] = $running;
    }
    return $runningStates;
}
/** @param array<int,string> $processNames */
function pmssUserWatchdogHandleSuspended(string $username, array $processNames, string $userLogMessage, string $homeRoot = '/home'): bool
{
    if (!pmssUserWebRootUnavailable($username, $homeRoot)) return false;
    echo "User: {$username} is suspended\n";
    pmssUserWatchdogTerminateProcesses($username, $processNames, 9);
    pmssUserLog($username, $userLogMessage);
    return true;
}
/** Run a watchdog callback for enabled, unsuspended managed users. */
function pmssUserWatchdogRunEnabledUsers(string $enableMarker, array $processNames, string $userLogMessage, callable $callback, string $homeRoot = '/home', string $command = '/scripts/listUsers.php'): void
{
    if ($enableMarker === '') { return; }
    $homeRoot = rtrim($homeRoot, '/');
    foreach (pmssListManagedUsers($command) as $username) {
        if (pmssUserWatchdogHandleSuspended($username, $processNames, $userLogMessage, $homeRoot) || !is_file($homeRoot.'/'.$username.'/.'.$enableMarker)) { continue; }
        $callback($username);
    }
}

/** @param array<int,string> $processNames @param array<int,array<string,mixed>> $serviceSpecs */
function pmssUserWatchdogRunService(string $heading, string $enableMarker, array $processNames, string $userLogMessage, array $serviceSpecs, ?callable $runningStateBuilder = null, ?string $optionalRequirePath = null, string $homeRoot = '/home', string $command = '/scripts/listUsers.php'): void
{
    $heading !== '' && print date('Y-m-d H:i:s') . ': Checking '.$heading." instances\n";
    $optionalRequirePath !== null && is_file($optionalRequirePath) && require_once $optionalRequirePath;
    pmssUserWatchdogRunEnabledUsers($enableMarker, $processNames, $userLogMessage, function (string $username) use ($serviceSpecs, $runningStateBuilder): void { pmssUserWatchdogEnsureServices($username, $serviceSpecs, $runningStateBuilder !== null ? (array) $runningStateBuilder($username) : array()); }, $homeRoot, $command);
}

function pmssManagedUsersSelectFromList(array $managedUsers, string $rawUsername = '', array $options = array()): array
{
    $managedUsers = pmssManagedUsersNormalizeList($managedUsers);
    $rawUsername = trim($rawUsername);
    if ($rawUsername === '') {
        if (!empty($options['emitEmptyMessage']) && $managedUsers === []) {
            $message = isset($options['emptyMessage']) ? (string) $options['emptyMessage'] : "No users setup - nothing to do\n";
            !empty($options['emptyToStderr']) ? fwrite(STDERR, $message) : print $message;
        }
        return array('exitCode' => 0, 'username' => '', 'users' => $managedUsers);
    }

    $strictInput = !empty($options['strictInput']);
    $normalizedUsername = pmssNormalizeUsername($rawUsername);
    $username = $strictInput
        ? (($normalizedUsername === $rawUsername && pmssValidateUsername($normalizedUsername)) ? $normalizedUsername : null)
        : pmssUsernameNormalizeIfValid($rawUsername);
    if ($username === null) {
        $message = isset($options['invalidMessage']) ? (string) $options['invalidMessage'] : "Invalid username\n";
        fwrite(STDERR, strpos($message, '%s') === false ? $message : sprintf($message, $normalizedUsername));

        return array('exitCode' => 1, 'username' => $normalizedUsername, 'users' => array());
    }

    $found = (!empty($options['lookupMode']) && $options['lookupMode'] === 'account')
        ? pmssUserAccountLookup($username) !== null
        : in_array($username, $managedUsers, true);
    if (!$found) {
        $message = isset($options['notFoundMessage']) ? (string) $options['notFoundMessage'] : "User not found\n";
        fwrite(STDERR, strpos($message, '%s') === false ? $message : sprintf($message, $username));

        return array('exitCode' => 1, 'username' => $username, 'users' => array());
    }
    return array('exitCode' => 0, 'username' => $username, 'users' => array($username));
}

/** @return array{exitCode:int,username:string,users:array<int,string>} */
function pmssManagedUsersSelectFromCommand(string $command = '/scripts/listUsers.php', string $rawUsername = '', array $options = array()): array
{
    return pmssManagedUsersSelectFromList(pmssListManagedUsers($command), $rawUsername, $options);
}

/** @return array{name:string,uid:int,gid:int,dir:string}|null */
function pmssPasswdEntryLookup(string $username, string $passwdPath = '/etc/passwd'): ?array
{
    $normalized = pmssUsernameNormalizeIfValid($username);
    if ($normalized === null || ($parts = pmssColonRecordFieldsLookup($passwdPath, $normalized, 7, false)) === null) {
        return null;
    }

    return array(
        'name' => (string) $parts[0],
        'uid' => (int) $parts[2],
        'gid' => (int) $parts[3],
        'dir' => (string) $parts[5],
    );
}

/** @return array<string,mixed>|null */
function pmssUserAccountLookup(string $username): ?array
{
    if (!function_exists('posix_getpwnam')) {
        return pmssPasswdEntryLookup($username);
    }
    $info = @posix_getpwnam($username);
    return is_array($info) && isset($info['uid']) ? $info : null;
}

/**
 * Return machine- and human-friendly create-validation failure details.
 *
 * @param string $username Candidate username (typically normalized input).
 *
 * @return array<string,string>|null Null when valid, otherwise code+detail.
 */
function pmssUsernameCreateValidationError(string $username): ?array
{
    $normalized = pmssNormalizeUsername($username);

    if (strpos($normalized, '@') !== false) {
        return array(
            'code' => 'email_not_allowed',
            'detail' => 'must be a bare username (email addresses are not allowed)',
        );
    }

    if (!pmssUsernameIsValid($normalized)) {
        return array(
            'code' => 'invalid_format',
            'detail' => 'must start with a lowercase letter and contain only lowercase letters or digits (max 8 chars)',
        );
    }

    if (strlen($normalized) < 3) {
        return array(
            'code' => 'too_short',
            'detail' => 'must be at least 3 characters long',
        );
    }

    if (pmssUsernameIsReserved($normalized)) {
        return array(
            'code' => 'reserved',
            'detail' => 'is reserved for system use',
        );
    }

    return null;
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
    pmssUserLifecycleContextLog(
        $action,
        $phase,
        $username,
        array_merge(
            array(
                'status' => $status,
                'message' => $message,
            ),
            $extra
        )
    );
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
    if ($dryRun) {
        pmssUserLifecycleContextLog($action, $step, $username, array(
            'step'     => $step,
            'command'  => $command,
            'rc'       => 0,
            'duration' => 0.0,
            'dry_run'  => true,
            'status'   => 'SKIP',
        ));
        echo "[DRY-RUN][{$action}] {$step}: {$command}\n";
        return 0;
    }

    $rc = 0;
    @passthru($command, $rc);
    $duration = microtime(true) - $started;

    pmssUserLifecycleContextLog($action, $step, $username, array(
        'step'     => $step,
        'command'  => $command,
        'rc'       => $rc,
        'duration' => round($duration, 4),
        'dry_run'  => false,
        'status'   => $rc === 0 ? 'OK' : 'ERR',
    ));

    return $rc;
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
    $ranked = array();
    $hasSuspendedContent = static function (string $candidate): bool {
        if (!is_dir($candidate) || is_dir($candidate.'/rutorrent') || is_file($candidate.'/index.php')) {
            return is_dir($candidate) && (is_dir($candidate.'/rutorrent') || is_file($candidate.'/index.php'));
        }
        $entries = @scandir($candidate);
        if ($entries === false || empty(array_diff($entries, array('.', '..')))) {
            return false;
        }
        foreach (array_diff($entries, array('.', '..')) as $entry) {
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
        $ranked[] = array('path' => $candidate, 'mtime' => $mtime === false ? 0 : $mtime);
    }
    if (empty($ranked)) {
        return null;
    }
    usort($ranked, static function (array $a, array $b): int {
        if ($a['mtime'] === $b['mtime']) {
            return strcmp($b['path'], $a['path']);
        }
        return $b['mtime'] <=> $a['mtime'];
    });

    return $ranked[0]['path'];
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
