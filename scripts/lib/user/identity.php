<?php
/**
 * Username and account identity helpers shared by user lifecycle flows.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/runtime.php';

/** Normalise a username for consistent comparisons. */
function pmssNormalizeUsername(string $username): string
{
    return strtolower(trim($username));
}

/** Validate the legacy PMSS username shape used by existing accounts. */
function pmssUsernameIsValid(string $username): bool
{
    $normalized = pmssNormalizeUsername($username);
    return $username !== ''
        && $normalized === $username
        && (bool) preg_match('/^[a-z][a-z0-9]{0,7}$/D', $normalized);
}

/** Return the reserved username list for system/service accounts. */
function pmssReservedUsernames(): array
{
    static $reserved = null;
    if ($reserved !== null) {
        return $reserved;
    }

    $reserved = array(
        '_apt', 'backup', 'bin', 'daemon', 'games', 'gnats', 'irc',
        'list', 'lp', 'mail', 'man', 'news', 'nobody', 'proxy',
        'root', 'sync', 'sys', 'uucp', 'www-data',
        'adm', 'audio', 'cdrom', 'dialout', 'dip', 'disk', 'fax',
        'floppy', 'kmem', 'nogroup', 'operator', 'plugdev', 'sasl',
        'shadow', 'src', 'staff', 'sudo', 'tape', 'tty', 'users',
        'utmp', 'video', 'voice',
        'alias', 'asterisk', 'ceph', 'ftn', 'grsec-proc', 'grsec-sock-all',
        'grsec-sock-clt', 'grsec-sock-srv', 'grsec-tpe', 'haclient',
        'hacluster', 'libvirt-qemu', 'mysql', 'netplan', 'opensrf',
        'qmail', 'qmaild', 'qmaill', 'qmailp', 'qmailq', 'qmailr',
        'qmails', 'slurm', 'tac-plus', 'vchkpw', 'vpopmail',
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
        'pmss', 'seedbox', 'rtorrent', 'deluge', 'qbittorrent',
        'lighttpd', 'rutorrent', 'srvadmin', 'srvapi', 'pmcseed',
        'pmcdn', 'srvmgmt',
    );

    return $reserved;
}

/** Return true when a username is reserved for system/service accounts. */
function pmssUsernameIsReserved(string $username): bool
{
    return in_array(pmssNormalizeUsername($username), pmssReservedUsernames(), true);
}

/** Return machine- and human-friendly create-validation failure details. */
function pmssUsernameCreateValidationError(string $username): ?array
{
    $normalized = pmssNormalizeUsername($username);
    if (strpos($normalized, '@') !== false) {
        return array('code' => 'email_not_allowed', 'detail' => 'must be a bare username (email addresses are not allowed)');
    }
    if (!pmssUsernameIsValid($normalized)) {
        return array('code' => 'invalid_format', 'detail' => 'must start with a lowercase letter and contain only lowercase letters or digits (max 8 chars)');
    }
    if (strlen($normalized) < 3) {
        return array('code' => 'too_short', 'detail' => 'must be at least 3 characters long');
    }
    if (pmssUsernameIsReserved($normalized)) {
        return array('code' => 'reserved', 'detail' => 'is reserved for system use');
    }

    return null;
}

/** Validate a username for new-user provisioning. */
function pmssUsernameIsValidForCreate(string $username): bool
{
    return pmssUsernameIsValid($username)
        && pmssUsernameCreateValidationError($username) === null;
}

/** Back-compat alias for validation helper used in legacy scripts/tests. */
function pmssValidateUsername(string $username): bool
{
    return pmssUsernameIsValid($username);
}

/** Normalize raw CLI/user input and return the username when it is valid. */
function pmssUsernameNormalizeIfValid(string $rawUsername): ?string
{
    $normalized = pmssNormalizeUsername($rawUsername);
    return pmssValidateUsername($normalized) ? $normalized : null;
}

/** Normalize a CLI username argument and abort with the legacy error text when invalid. */
function pmssRequireCliUsername(string $rawUsername, string $action, string $errorFormat, string $logMessage = 'Rejected username due to validation failure'): string
{
    $normalized = pmssUsernameNormalizeIfValid($rawUsername);
    if ($normalized !== null) {
        return $normalized;
    }

    $normalized = pmssNormalizeUsername($rawUsername);
    if (function_exists('pmssUserLifecycleContextLogStatusMessage')) {
        pmssUserLifecycleContextLogStatusMessage($action, 'validate', $normalized, 'ERR', $logMessage);
    }
    die(sprintf($errorFormat, $normalized));
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

/** Resolve a strictly positive UID from POSIX/passwd entry data. */
function pmssPasswdEntryPositiveUid($passwdEntry): ?int
{
    if (!is_array($passwdEntry) || !array_key_exists('uid', $passwdEntry)) {
        return null;
    }

    $uid = $passwdEntry['uid'];
    if (is_int($uid)) {
        return $uid > 0 ? $uid : null;
    }

    if (!is_string($uid) || preg_match('/^[1-9][0-9]*$/D', $uid) !== 1) {
        return null;
    }

    $parsed = filter_var($uid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return is_int($parsed) ? $parsed : null;
}

/** @return array<string,mixed>|null */
function pmssUserAccountLookup(string $username): ?array
{
    if (!function_exists('posix_getpwnam')) return pmssPasswdEntryLookup($username);
    $info = @posix_getpwnam($username);
    return is_array($info) && isset($info['uid']) ? $info : null;
}
