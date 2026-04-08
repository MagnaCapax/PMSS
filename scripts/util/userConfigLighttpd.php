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
require_once dirname(__DIR__).'/lib/update/distro.php';
require_once dirname(__DIR__).'/lib/userLifecycle.php';
require_once dirname(__DIR__).'/lib/user/directories.php';

const PMSS_LIGHTTPD_CHILDREN_PER_PROC = 6;
const PMSS_PHP_MEMORY_MIN_MB = 125;
const PMSS_PHP_MEMORY_MAX_MB = 1024;
// Minimum/maximum total php-cgi threads per user (max-procs * children).
const PMSS_PHP_THREADS_MIN = 3;
const PMSS_PHP_THREADS_MAX = 48;

require_once dirname(__DIR__).'/lib/lighttpd/userFileWrite.php';
require_once dirname(__DIR__).'/lib/lighttpd/userConfigApply.php';

function pmssUserConfigLighttpdMain(array $argv): int
{
    $invokedAs = basename($argv[0] ?? '');
    if ($invokedAs !== basename(__FILE__)) {
        fwrite(STDERR, "#### WARNING: DEPRECATED COMMAND (use ".basename(__FILE__).")\n");
    }

    $selection = pmssManagedUsersSelectFromCommand('/scripts/listUsers.php', (string) ($argv[1] ?? ''), array('emitEmptyMessage' => true, 'emptyToStderr' => true, 'notFoundMessage' => "Username not found\n"));
    if ($selection['exitCode'] !== 0 || $selection['users'] === array()) return $selection['exitCode'];
    $users = $selection['users'];
    $portsDirectory = '/etc/seedbox/runtime/ports';
    if (!file_exists($portsDirectory))  {
        pmssDirEnsureExists($portsDirectory, 0600);
    }
    if (is_dir($portsDirectory) && !is_link($portsDirectory)) {
        @chmod($portsDirectory, 0600);
    }
    if (!file_exists('/root/backups')) {
        pmssDirEnsureExists('/root/backups', 0755);
    }
    $template = file_get_contents("/etc/seedbox/config/template.lighttpd");

    $distroInfo = pmssDetectDistro();
    $distroVersion = (int) ($distroInfo['version'] ?? 0);
    // Debian 11/12 ship mod_deflate; compress.* triggers deprecation on bookworm.
    if ($distroVersion >= 11) {
        $template = str_replace(
            array('compress.cache-dir', 'compress.filetype', '"mod_compress"'),
            array('deflate.cache-dir', 'deflate.mimetypes', '"mod_deflate"'),
            $template
        );
    }
    $deflateEnabled = (bool) preg_match('/^[ \t]*deflate\./m', $template);

    if (empty(glob('/usr/lib*/lighttpd/mod_webdav.so'))) {
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

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssUserConfigLighttpdMain');
