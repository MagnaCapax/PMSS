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

if (!defined('PMSS_USER_LOG_TEXT')) {
    define('PMSS_USER_LOG_TEXT', '/var/log/pmss/users.log');
}

if (!defined('PMSS_USER_LOG_JSON')) {
    define('PMSS_USER_LOG_JSON', '/var/log/pmss/users.jsonl');
}

if (!function_exists('pmssUserLogAllowed')) {
    function pmssUserLogAllowed(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $uid = function_exists('posix_geteuid') ? @posix_geteuid() : null;
        if ($uid === null) {
            $status = @file_get_contents('/proc/self/status');
            if ($status !== false && preg_match('/^Uid:\\s+(\\d+)/m', $status, $matches)) {
                $uid = (int) $matches[1];
            }
        }

        return $cached = ($uid === 0);
    }
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
 * Provisioning wrapper matching the legacy "Validate*" naming convention.
 */
function pmssValidateUsernameForCreate(string $username): bool
{
    return pmssUsernameIsValidForCreate($username);
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
    $users = array();
    foreach (array_filter(array_map('trim', explode("\n", trim((string) @shell_exec($command)))), 'strlen') as $rawUser) {
        $normalized = pmssNormalizeUsername($rawUser);
        if (!pmssValidateUsername($normalized)) {
            continue;
        }
        $users[$normalized] = true;
    }

    return array_keys($users);
}

/** @return array<string,mixed>|null */
function pmssUserAccountLookup(string $username): ?array
{
    if (!function_exists('posix_getpwnam')) { return null; }
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
 * Log a user-facing fix notification.
 *
 * Writes to a user-readable log file in the user's home directory so they can
 * see what automated fixes have been applied to their service. Used by cron
 * maintenance scripts (checkRtorrent.php, checkLighttpd.php, quota handlers).
 *
 * @param string $username  Target user (must be a valid PMSS username)
 * @param string $component Component name (QUOTA, RTORRENT, LIGHTTPD, etc.)
 * @param string $message   Human-readable description of the fix applied
 */
function pmssUserFixLog(string $username, string $component, string $message): void
{
    // Validate username to prevent path traversal
    if (!pmssUsernameIsValid($username)) {
        return;
    }

    $homeDir = "/home/{$username}";
    if (!is_dir($homeDir)) {
        return;
    }

    $logPath = "{$homeDir}/.pmss-fixes.log";
    $ts = date('Y-m-d H:i:s');
    $line = "{$ts} [{$component}] {$message}\n";

    // Write the log entry
    if (@file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX) === false) {
        return;
    }

    // Ensure user can read their own log
    @chown($logPath, $username);
}
