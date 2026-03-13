#!/usr/bin/env php
<?php
/**
 * Resource-aware per-user lighttpd configuration.
 *
 * Replaces the legacy configureLighttpd.php entrypoint while preserving its
 * interface. The script keeps idempotent lighttpd configs, applies sensible
 * php-cgi limits derived from user cgroup settings, and adjusts php.ini
 * memory_limit with safe clamps.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/lib/update/systemPrep.php';
require_once dirname(__DIR__).'/lib/userLifecycle.php';
require_once dirname(__DIR__).'/lib/user/directories.php';

const PMSS_LIGHTTPD_CHILDREN_PER_PROC = 2;
const PMSS_PHP_MEMORY_MIN_MB = 125;
const PMSS_PHP_MEMORY_MAX_MB = 1024;
// Minimum/maximum total php-cgi threads per user (max-procs * children).
const PMSS_PHP_THREADS_MIN = 3;
const PMSS_PHP_THREADS_MAX = 48;

require_once dirname(__DIR__).'/lib/lighttpd/userFileWrite.php';
require_once dirname(__DIR__).'/lib/lighttpd/resourcePlan.php';
require_once dirname(__DIR__).'/lib/lighttpd/userDirectoriesPrepare.php';
require_once dirname(__DIR__).'/lib/lighttpd/delugeWebConf.php';
require_once dirname(__DIR__).'/lib/lighttpd/proxyFragments.php';
require_once dirname(__DIR__).'/lib/lighttpd/configRender.php';
require_once dirname(__DIR__).'/lib/lighttpd/userConfigApply.php';

function pmssUserConfigLighttpdMain(array $argv): int
{
    $invokedAs = basename($argv[0] ?? '');
    if ($invokedAs !== basename(__FILE__)) {
        fwrite(STDERR, "#### WARNING: DEPRECATED COMMAND (use ".basename(__FILE__).")\n");
    }

    $rawUsers = shell_exec('/scripts/listUsers.php');
    $users = [];
    foreach (explode("\n", trim((string)$rawUsers)) as $rawUser) {
        $rawUser = trim($rawUser);
        if ($rawUser === '') {
            continue;
        }
        $normalized = pmssNormalizeUsername($rawUser);
        if (!pmssValidateUsername($normalized)) {
            fwrite(STDERR, "Skipping invalid username from listUsers: ".substr($normalized, 0, 20)."\n");
            continue;
        }
        $users[] = $normalized;
    }
    $users = array_values(array_unique($users));
    if (count($users) === 0) {
        fwrite(STDERR, "No users setup - nothing to do\n");
        return 0;
    }

    if (isset($argv[1]) && $argv[1] !== '') {
        $argUsername = pmssNormalizeUsername((string)$argv[1]);
        if (!pmssValidateUsername($argUsername)) {
            fwrite(STDERR, "Invalid username\n");
            return 1;
        }
        if (in_array($argUsername, $users, true)) {
            $users = array($argUsername);   // Only do this user
        } else {
            fwrite(STDERR, "Username not found\n");
            return 1;
        }
    }

    $portsDirectory = '/etc/seedbox/runtime/ports';
    if (!file_exists($portsDirectory))  {
        @mkdir($portsDirectory, 0600, true);
    }
    if (is_dir($portsDirectory) && !is_link($portsDirectory)) {
        @chmod($portsDirectory, 0600);
    }
    if (!file_exists('/root/backups')) {
        @mkdir('/root/backups', 0755, true);
    }
    $template = file_get_contents("/etc/seedbox/config/template.lighttpd");

    $osReleasePath = getenv('PMSS_OS_RELEASE_PATH');
    if ($osReleasePath === false || $osReleasePath === '') {
        $osReleasePath = '/etc/os-release';
    }
    $distroVersion = 0;
    if (is_readable($osReleasePath)) {
        $osReleaseData = @file_get_contents($osReleasePath);
        if ($osReleaseData !== false
            && preg_match('/^VERSION_ID=\"?([0-9]+)/m', $osReleaseData, $matches)) {
            $distroVersion = (int) $matches[1];
        }
    }
    // Debian 11/12 ship mod_deflate; compress.* triggers deprecation on bookworm.
    if ($distroVersion >= 11) {
        $template = str_replace(
            array('compress.cache-dir', 'compress.filetype', '"mod_compress"'),
            array('deflate.cache-dir', 'deflate.mimetypes', '"mod_deflate"'),
            $template
        );
    }
    $deflateEnabled = (bool) preg_match('/^[ \t]*deflate\./m', $template);

    if (!pmssLighttpdWebdavModulePresent()) {
        $template = pmssStripLighttpdWebdavConfig($template);
    }

    $policyDefaults = [
        'memoryHighMiB'   => 500,
        'memoryMaxMiB'    => 750,
        'cpuQuotaPercent' => 100,
    ];
    $policyFile = '/etc/seedbox/config/cgroup.policy.php';
    if (is_readable($policyFile)) {
        $data = include $policyFile;
        if (is_array($data)) {
            $policyDefaults = $data;
        }
    }

    foreach ($users as $thisUser) {
        pmssUserConfigLighttpdConfigureUser($thisUser, $portsDirectory, $template, $deflateEnabled, $policyDefaults);
    }

    return 0;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(pmssUserConfigLighttpdMain($argv));
}
