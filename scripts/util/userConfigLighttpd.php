#!/usr/bin/env php
<?php
/**
 * Resource-aware per-user lighttpd configuration.
 *
 * Replaces the legacy configureLighttpd.php entrypoint while preserving its
 * interface. The script keeps idempotent lighttpd configs, applies sensible
 * php-cgi limits derived from user cgroup settings, and adjusts php.ini
 * memory_limit with safe clamps.
 */

require_once dirname(__DIR__).'/lib/update/systemPrep.php';
require_once dirname(__DIR__).'/lib/userLifecycle.php';

const PMSS_LIGHTTPD_CHILDREN_PER_PROC = 2;
const PMSS_PHP_MEMORY_MIN_MB = 125;
const PMSS_PHP_MEMORY_MAX_MB = 1024;
// Minimum/maximum total php-cgi threads per user (max-procs * children).
const PMSS_PHP_THREADS_MIN = 3;
const PMSS_PHP_THREADS_MAX = 48;

function pmssParseSizeToMiB($value): ?int
{
    $raw = trim((string)$value);
    if ($raw === '' || $raw === 'infinity' || $raw === '0') {
        return null;
    }

    if (preg_match('/^([0-9.]+)\s*([KMG])?B?$/i', $raw, $m)) {
        $num = (float)$m[1];
        $unit = strtolower($m[2] ?? '');
        $factor = 1;
        if ($unit === 'k') {
            $factor = 1 / 1024;
        } elseif ($unit === 'm') {
            $factor = 1;
        } elseif ($unit === 'g') {
            $factor = 1024;
        } else {
            // No unit → assume bytes
            return (int)round($num / 1048576);
        }
        return (int)round($num * $factor);
    }

    // Fallback: assume raw bytes
    if (is_numeric($raw)) {
        return (int)round(((float)$raw) / 1048576);
    }

    return null;
}

function pmssClampMemoryLimit(int $memoryMiB): int
{
    $bounded = max(PMSS_PHP_MEMORY_MIN_MB, min(PMSS_PHP_MEMORY_MAX_MB, $memoryMiB));
    return $bounded;
}

function pmssExtractCpuQuotaPercent(array $props, array $policyDefaults): int
{
    $quota = null;

    // Prefer explicit slice CPUQuota value when present.
    if (isset($props['CPUQuota'])) {
        $raw = trim((string)$props['CPUQuota']);
        if ($raw !== '' && stripos($raw, 'infinity') === false) {
            if (strpos($raw, '%') !== false) {
                $quota = (int)round((float)$raw);
            }
        }
    }

    // Derive quota from period values when CPUQuota is not set directly.
    if ($quota === null) {
        $perSec = $props['CPUQuotaPerSecUSec'] ?? null;
        $period = $props['CPUQuotaPeriodUSec'] ?? null;
        if (is_numeric($perSec) && is_numeric($period) && (float)$period > 0.0) {
            $quota = (int)round(((float)$perSec / (float)$period) * 100);
        }
    }

    // When quota is explicitly set and not a legacy 85% sentinel, use it as-is.
    if ($quota !== null && $quota > 0 && $quota !== 85) {
        return $quota;
    }

    // Fallback if no usable systemd property is found:
    // Use policy default when it is a concrete value other than the legacy 85%.
    if (isset($policyDefaults['cpuQuotaPercent']) && is_numeric($policyDefaults['cpuQuotaPercent'])) {
        $policyQuota = (int)$policyDefaults['cpuQuotaPercent'];
        if ($policyQuota > 0 && $policyQuota !== 85) {
            return $policyQuota;
        }
    }

    // Legacy 85% (either from slice or policy) and "no quota" fall through to a
    // host-based default: ~85% per logical CPU thread, but never below 200%.
    $threads = pmssTotalCpuThreads();
    $default = $threads > 0 ? $threads * 85 : 200;
    return max(200, $default);
}

function pmssReadUserSliceProps(string $user): array
{
    if (!function_exists('posix_getpwnam')) {
        return [];
    }
    $info = posix_getpwnam($user);
    if (!is_array($info) || !isset($info['uid'])) {
        return [];
    }
    $slice = sprintf('user-%d.slice', (int)$info['uid']);
    $cmd = 'systemctl show '.escapeshellarg($slice).' -p MemoryHigh -p MemoryMax -p CPUQuotaPerSecUSec -p CPUQuotaPeriodUSec -p CPUQuota';
    $out = @shell_exec($cmd);
    $props = [];
    if (!is_string($out)) {
        return $props;
    }
    foreach (preg_split('/\r?\n/', trim($out)) as $line) {
        if ($line === '') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = substr($line, 0, $pos);
        $val = substr($line, $pos + 1);
        $props[$key] = $val;
    }
    return $props;
}

function pmssComputePhpProcessPlan(float $cpuQuotaPercent): array
{
    // Scale worker threads with CPU quota (approx. 4 threads per 100% quota),
    // then clamp to safe bounds (3..48 total threads).
    $effectiveQuota = $cpuQuotaPercent > 0 ? $cpuQuotaPercent : 100;
    $targetThreads = (int)ceil(($effectiveQuota / 100) * 4);
    $targetThreads = max(PMSS_PHP_THREADS_MIN, min(PMSS_PHP_THREADS_MAX, $targetThreads));

    $childrenPerProc = PMSS_LIGHTTPD_CHILDREN_PER_PROC;
    $maxProcs = (int)ceil($targetThreads / $childrenPerProc);
    $totalThreads = $maxProcs * $childrenPerProc;

    // Ensure we do not exceed global cap after rounding.
    if ($totalThreads > PMSS_PHP_THREADS_MAX) {
        $maxProcs = (int)ceil(PMSS_PHP_THREADS_MAX / $childrenPerProc);
        $totalThreads = $maxProcs * $childrenPerProc;
    }

    return [
        'max_procs'    => $maxProcs,
        'children'     => $childrenPerProc,
        'totalThreads' => $totalThreads,
    ];
}

function pmssResolveUserResources(string $user, array $policyDefaults): array
{
    $props = pmssReadUserSliceProps($user);

    $memoryHigh = null;
    if (isset($props['MemoryHigh'])) {
        $memoryHigh = pmssParseSizeToMiB($props['MemoryHigh']);
    }
    if ($memoryHigh === null && isset($props['MemoryMax'])) {
        $memoryHigh = pmssParseSizeToMiB($props['MemoryMax']);
    }
    if ($memoryHigh === null && isset($policyDefaults['memoryHighMiB'])) {
        $memoryHigh = (int)$policyDefaults['memoryHighMiB'];
    }
    if ($memoryHigh === null) {
        $memoryHigh = 512;
    }

    $phpMemoryLimit = pmssClampMemoryLimit((int)$memoryHigh);
    $cpuQuotaPercent = pmssExtractCpuQuotaPercent($props, $policyDefaults);
    $plan = pmssComputePhpProcessPlan($cpuQuotaPercent);

    return [
        'memoryLimit'     => $phpMemoryLimit,
        'cpuQuotaPercent' => $cpuQuotaPercent,
        'maxProcs'        => $plan['max_procs'],
        'children'        => $plan['children'],
        'totalThreads'    => $plan['totalThreads'],
    ];
}

function pmssShouldConfigureLighttpdForHome(string $homeDir): bool
{
    if (!is_dir($homeDir)) {
        return false;
    }
    if (is_dir($homeDir.'/www-disabled') || !is_dir($homeDir.'/www')) {
        return false;
    }
    if (!file_exists($homeDir.'/.rtorrent.rc')) {
        return false;
    }
    return true;
}

/**
 * Ensure the WebDAV lock database is present and owned by the target user.
 */
function pmssEnsureWebdavLockDatabase(string $user, string $homeDir): void
{
    $lighttpdDir = $homeDir.'/.lighttpd';
    if (!is_dir($lighttpdDir) || is_link($lighttpdDir)) {
        return;
    }

    $lockFile = $lighttpdDir.'/webdav.lock.db';
    if (is_link($lockFile)) {
        return;
    }
    if (!file_exists($lockFile)) {
        @touch($lockFile);
    }
    if (!is_file($lockFile)) {
        return;
    }
    @chmod($lockFile, 0600);

    if (function_exists('posix_geteuid') && @posix_geteuid() === 0) {
        @chown($lighttpdDir, $user);
        @chgrp($lighttpdDir, $user);
        @chown($lockFile, $user);
        @chgrp($lockFile, $user);
    }
}

function pmssUpdatePhpIni(string $path, int $memoryLimitMb): void
{
    $content = @file_get_contents($path);
    if ($content === false) {
        return;
    }
    $memoryLine = 'memory_limit = '.$memoryLimitMb.'M';
    if (preg_match('/^memory_limit\s*=.*$/m', $content)) {
        $content = preg_replace('/^memory_limit\s*=.*$/m', $memoryLine, $content, 1);
    } else {
        $content = rtrim($content, "\n")."\n".$memoryLine."\n";
    }
    file_put_contents($path, $content);
}

function pmssSplitFirstJsonObject(string $content): ?array
{
    $len = strlen($content);
    $i = 0;

    while ($i < $len) {
        $ch = $content[$i];
        if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
            $i++;
            continue;
        }
        break;
    }

    if ($i >= $len || $content[$i] !== '{') {
        return null;
    }

    $start = $i;
    $depth = 0;
    $inString = false;
    $escape = false;

    for (; $i < $len; $i++) {
        $ch = $content[$i];
        if ($inString) {
            if ($escape) {
                $escape = false;
                continue;
            }
            if ($ch === '\\') {
                $escape = true;
                continue;
            }
            if ($ch === '"') {
                $inString = false;
            }
            continue;
        }

        if ($ch === '"') {
            $inString = true;
            continue;
        }
        if ($ch === '{') {
            $depth++;
            continue;
        }
        if ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                $end = $i + 1;
                return [
                    substr($content, $start, $end - $start),
                    substr($content, $end),
                ];
            }
        }
    }

    return null;
}

function pmssDelugeReadWebConf(string $path): ?array
{
    $raw = @file_get_contents($path);
    if (!is_string($raw)) {
        return null;
    }
    if (strpos($raw, "\0") !== false) {
        // Deluge web.conf is expected to be plain text JSON (two objects).
        // Treat NUL bytes as corruption/malicious input and refuse to parse.
        return null;
    }

    $split = pmssSplitFirstJsonObject($raw);
    if (!is_array($split) || count($split) !== 2) {
        return null;
    }

    $meta = json_decode($split[0], true);
    if (!is_array($meta) || json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    $config = json_decode(ltrim((string)$split[1]), true);
    if (!is_array($config) || json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    return ['meta' => $meta, 'config' => $config];
}

function pmssDelugeWriteWebConf(string $path, array $meta, array $config, string $owner): bool
{
    $existingMode = @fileperms($path);
    $mode = is_int($existingMode) ? ($existingMode & 0777) : 0600;

    $metaJson = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $configJson = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($metaJson === false || $configJson === false) {
        return false;
    }

    $tmp = $path.'.pmss-tmp';
    if (file_put_contents($tmp, $metaJson.$configJson) === false) {
        @unlink($tmp);
        return false;
    }

    @chmod($tmp, $mode);
    @chown($tmp, $owner);
    @chgrp($tmp, $owner);

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    @chmod($path, $mode);
    @chown($path, $owner);
    @chgrp($path, $owner);

    return true;
}

function pmssDelugeLighttpdProxyFragment(string $user, int $webPort): string
{
    $legacy = "/deluge-{$user}/";
    $canonical = "/user-{$user}/deluge/";
    $deprecationDate = '2028-01-28';

    return <<<LIGHTTPD
# PMSS-managed: Deluge reverse proxy.
# Legacy path {$legacy} kept for compatibility until at least {$deprecationDate}.

\$HTTP["url"] =~ "^/user-{$user}/deluge($|/)" {
  proxy.server = ( "" => ( (
    "host" => "127.0.0.1",
    "port" => {$webPort}
  ) ) )
}

\$HTTP["url"] =~ "^/deluge-{$user}($|/)" {
  proxy.server = ( "" => ( (
    "host" => "127.0.0.1",
    "port" => {$webPort}
  ) ) ),
  proxy.header = (
      "map-urlpath" => (
         "/deluge-{$user}/"  => "{$canonical}",
         "/deluge-{$user}" => "/user-{$user}/deluge"
       )
  )
}

LIGHTTPD;
}

function pmssRcloneLighttpdProxyFragment(string $user, int $port): string
{
    return <<<LIGHTTPD
# PMSS-managed: rclone reverse proxy.

\$HTTP["url"] =~ "^/user-{$user}/rclone/" {
  proxy.server = ( "" => ( (
    "host" => "127.0.0.1",
    "port" => {$port}
  ) ) )
}

LIGHTTPD;
}

function pmssQbittorrentLighttpdProxyFragment(string $user, int $port): string
{
    return <<<LIGHTTPD
# PMSS-managed: qBittorrent reverse proxy.

\$HTTP["url"] =~ "^/user-{$user}/qbittorrent/" {
  proxy.server = ( "" => ( (
    "host" => "127.0.0.1",
    "port" => {$port}
  ) ) ),
  proxy.forwarded = ( "for" => 1,
                      "host" => 1,
                      "by" => 1
  ),
  proxy.header = (
      "map-urlpath" => (
         "/user-{$user}/qbittorrent/"  => "/",
         "/user-{$user}/qbittorrent" => ""
       )
  )
}

LIGHTTPD;
}

function pmssRenderLighttpdConfig(string $template, string $user, int $serverPort, int $rclonePort, int $qbittorrentPort, array $resources): string
{
    $webdavWwwPolicy = pmssWebdavWwwPolicyBlock($user);
    $config = str_replace(
        array("##username", "##serverPort", "##rclonePort", "##qbittorrentPort", "##PMSS_WEBDAV_WWW_POLICY##"),
        array($user, $serverPort, $rclonePort, $qbittorrentPort, $webdavWwwPolicy),
        $template
    );

    $config = preg_replace(
        '/("max-procs"\s*=>\s*)[0-9]+/',
        '${1}'.$resources['maxProcs'],
        $config,
        1
    );
    $config = preg_replace(
        '/("PHP_FCGI_CHILDREN"\s*=>\s*")[0-9]+(")/',
        '${1}'.$resources['children'].'${2}',
        $config,
        1
    );

    return $config;
}

function pmssWebdavWwwPolicyBlock(string $user): string
{
    // Defense-in-depth: validate username even though upstream should have validated.
    // Reject invalid usernames to prevent regex injection or path traversal in lighttpd config.
    // Valid PMSS usernames: ^[a-z][a-z0-9]{0,7}$ (1-8 chars, starts with letter, alphanumeric).
    if (!pmssUsernameIsValid($user)) {
        // Return safe empty block rather than generating config with untrusted input.
        // Log a warning so operators can investigate how invalid input reached here.
        error_log("pmssWebdavWwwPolicyBlock: rejected invalid username: " . substr($user, 0, 20));
        return '# WebDAV www policy skipped: invalid username';
    }

    // Default: keep ~/www read-only over WebDAV to prevent users from breaking the web stack.
    // Allow writing to ~/www/public by default, and allow full ~/www write if the user opts in.
    $marker = "/home/{$user}/.lighttpd/webdav.www-writable";
    if (file_exists($marker)) {
        return <<<LIGHTTPD
\$HTTP["url"] =~ "^/webdav-{$user}/www(\$|/)" {
    webdav.is-readonly = "disable"
}
LIGHTTPD;
    }

    return <<<LIGHTTPD
\$HTTP["url"] =~ "^/webdav-{$user}/www(\$|/)" {
    webdav.is-readonly = "enable"
}
\$HTTP["url"] =~ "^/webdav-{$user}/www/public(\$|/)" {
    webdav.is-readonly = "disable"
}
LIGHTTPD;
}

function pmssLighttpdWebdavModulePresent(): bool
{
    // Debian packages typically install into /usr/lib/lighttpd or /usr/lib/*/lighttpd.
    $paths = glob('/usr/lib*/lighttpd/mod_webdav.so');
    if (is_array($paths) && count($paths) > 0) {
        return true;
    }
    return false;
}

function pmssStripLighttpdWebdavConfig(string $template): string
{
    // Strip the managed WebDAV block (if present) to keep lighttpd start-safe on hosts
    // where the module is missing or was manually removed.
    $template = preg_replace(
        '/^\\s*#\\s*PMSS_WEBDAV_BEGIN\\s*$.*^\\s*#\\s*PMSS_WEBDAV_END\\s*$\\s*/ms',
        '',
        $template
    );

    // Comment out the module line if present.
    $template = preg_replace(
        '/^(\\s*)\"mod_webdav\",\\s*$/m',
        '${1}#"mod_webdav",',
        $template,
        1
    );

    // Remove placeholder that would otherwise leak into lighttpd.conf.
    $template = str_replace('##PMSS_WEBDAV_WWW_POLICY##', '', $template);

    return (string)$template;
}

function pmssUserConfigLighttpdMain(array $argv): int
{
    $invokedAs = basename($argv[0] ?? '');
    if ($invokedAs !== basename(__FILE__)) {
        fwrite(STDERR, "#### WARNING: DEPRECATED COMMAND (use ".basename(__FILE__).")\n");
    }

    $users = shell_exec('/scripts/listUsers.php');
    $users = explode("\n", trim($users));
    if (count($users) === 0) {
        fwrite(STDERR, "No users setup - nothing to do\n");
        return 0;
    }

    if (isset($argv[1]) && !empty($argv[1])) {
        $argUsername = pmssNormalizeUsername((string) $argv[1]);
        if (in_array($argUsername, $users, true)) {
            $users = array($argUsername);   // Only do this user
        } else {
            fwrite(STDERR, "Username not found\n");
            return 1;
        }
    }

    $portsDirectory = '/etc/seedbox/runtime/ports';
    if (!file_exists($portsDirectory))  {
        mkdir($portsDirectory, 0600, true);
        passthru("chmod 600 {$portsDirectory}");
    }
    if (!file_exists('/root/backups')) `mkdir /root/backups`;
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
        $thisUser = trim($thisUser);
        if ($thisUser === '') {
            continue;
        }

        $homeDir = "/home/{$thisUser}";
        if (!pmssShouldConfigureLighttpdForHome($homeDir)) {
            continue;
        }

        $portFile = "{$portsDirectory}/lighttpd-{$thisUser}";
        if (file_exists($portFile)) {
            $serverPort = (int) file_get_contents($portFile);
        } else {
            // Allocate a unique port using portManager utility
            $serverPort = (int) trim(shell_exec("/scripts/util/portManager.php assign {$thisUser} lighttpd"));
        }

        // Prepare directories and defaults
        $lighttpdDir = "/home/{$thisUser}/.lighttpd";
        if (!file_exists($lighttpdDir)) {
            passthru("cp -Rp /etc/skel/.lighttpd /home/{$thisUser}/");
            passthru("chown {$thisUser}:{$thisUser} {$lighttpdDir} -R");
            passthru("chmod 751 {$lighttpdDir} -R");
        }
        pmssEnsureWebdavLockDatabase($thisUser, $homeDir);
        if (!file_exists("/home/{$thisUser}/www/public")) {
            passthru("mkdir -p /home/{$thisUser}/www/public");
            passthru("chown {$thisUser}:{$thisUser} /home/{$thisUser}/www/public");
            passthru("chmod 751 /home/{$thisUser}/www/public -R");
        }
        if (!file_exists("/home/{$thisUser}/.lighttpd/custom.d")) {
            passthru("mkdir /home/{$thisUser}/.lighttpd/custom.d");
            passthru("chown {$thisUser}:{$thisUser} /home/{$thisUser}/.lighttpd/custom.d");
            passthru("chmod 750 /home/{$thisUser}/.lighttpd/custom.d");
        }
        $customDir = "/home/{$thisUser}/.lighttpd/custom.d";
        $uploadDir = "/home/{$thisUser}/.lighttpd/upload";
        if (!is_dir($uploadDir)) {
            passthru("mkdir -p {$uploadDir}");
            passthru("chown {$thisUser}:{$thisUser} {$uploadDir}");
            passthru("chmod 751 {$uploadDir}");
        }
        if ($deflateEnabled) {
            $compressDir = "/home/{$thisUser}/.lighttpd/compress";
            if (!is_dir($compressDir)) {
                passthru("mkdir -p {$compressDir}");
                passthru("chown {$thisUser}:{$thisUser} {$compressDir}");
                passthru("chmod 751 {$compressDir}");
            }
        }

        // Rclone port
        $rclonePort = (int) trim(@file_get_contents("/home/{$thisUser}/.rclonePort"));
        if ($rclonePort < 1024 or $rclonePort > 65500) {
            $rclonePort = (int) round(rand(1500, 65500));
            file_put_contents("/home/{$thisUser}/.rclonePort", $rclonePort);
        }

        // qBittorrent port
        $qbittorrentPort = (int) trim(@file_get_contents("/home/{$thisUser}/.qbittorrentPort"));
        if ($qbittorrentPort < 1024 or $qbittorrentPort > 65500) {
            $qbittorrentPort = (int) round(rand(1500, 65500));
            file_put_contents("/home/{$thisUser}/.qbittorrentPort", $qbittorrentPort);
        }

        // PMSS-managed proxy fragments under ~/.lighttpd/custom.d/
        $rcloneConfPath = "{$customDir}/pmss-rclone.conf";
        file_put_contents($rcloneConfPath, pmssRcloneLighttpdProxyFragment($thisUser, $rclonePort));
        @chown($rcloneConfPath, $thisUser);
        @chgrp($rcloneConfPath, $thisUser);
        @chmod($rcloneConfPath, 0640);

        $qbittorrentConfPath = "{$customDir}/pmss-qbittorrent.conf";
        file_put_contents($qbittorrentConfPath, pmssQbittorrentLighttpdProxyFragment($thisUser, $qbittorrentPort));
        @chown($qbittorrentConfPath, $thisUser);
        @chgrp($qbittorrentConfPath, $thisUser);
        @chmod($qbittorrentConfPath, 0640);

        // Deluge: generate a per-user proxy fragment under ~/.lighttpd/custom.d/
        // so nginx stays a lightweight reverse proxy.
        $delugeWebPort = null;
        $delugeWebConfPath = "/home/{$thisUser}/.config/deluge/web.conf";
        $delugeParsed = null;
        if (is_readable($delugeWebConfPath)) {
            $delugeParsed = pmssDelugeReadWebConf($delugeWebConfPath);
            if (is_array($delugeParsed) && isset($delugeParsed['config'], $delugeParsed['meta']) && is_array($delugeParsed['config']) && is_array($delugeParsed['meta'])) {
                $port = $delugeParsed['config']['port'] ?? null;
                if (is_int($port) && $port >= 1024 && $port <= 65535) {
                    $delugeWebPort = $port;
                }

                $expectedBase = "/user-{$thisUser}/deluge/";
                $expectedBaseNoSlash = "/user-{$thisUser}/deluge";
                $legacyBase = "/deluge-{$thisUser}/";
                $legacyBaseNoSlash = "/deluge-{$thisUser}";
                $base = $delugeParsed['config']['base'] ?? null;
                if (is_string($base) && ($base === $legacyBase || $base === $legacyBaseNoSlash || $base === $expectedBaseNoSlash) && $base !== $expectedBase) {
                    $delugeParsed['config']['base'] = $expectedBase;
                    if (pmssDelugeWriteWebConf($delugeWebConfPath, $delugeParsed['meta'], $delugeParsed['config'], $thisUser)) {
                        // Apply base change on the next cron tick (checkDelugeInstances.php).
                        passthru('killall -u '.escapeshellarg($thisUser).' -TERM deluge-web 2>/dev/null || true');
                    }
                }
            }
        }
        if ($delugeWebPort === null) {
            // Fallback (legacy hosts): derive deluge-web port from .delugePort.
            $delugePort = (int) trim(@file_get_contents("/home/{$thisUser}/.delugePort"));
            if ($delugePort >= 1024 && $delugePort <= 65535) {
                $candidatePorts = [$delugePort + 1, $delugePort];
                foreach ($candidatePorts as $candidate) {
                    if ($candidate < 1024 || $candidate > 65535) {
                        continue;
                    }
                    $sock = @fsockopen('127.0.0.1', $candidate, $errno, $errstr, 0.2);
                    if (is_resource($sock)) {
                        fclose($sock);
                        $delugeWebPort = $candidate;
                        break;
                    }
                }
                if ($delugeWebPort === null) {
                    $candidate = $delugePort + 1;
                    $delugeWebPort = ($candidate >= 1024 && $candidate <= 65535) ? $candidate : $delugePort;
                }
            }
        }
        if ($delugeWebPort !== null) {
            $delugeConfPath = "/home/{$thisUser}/.lighttpd/custom.d/pmss-deluge.conf";
            file_put_contents($delugeConfPath, pmssDelugeLighttpdProxyFragment($thisUser, $delugeWebPort));
            @chown($delugeConfPath, $thisUser);
            @chgrp($delugeConfPath, $thisUser);
            @chmod($delugeConfPath, 0640);
        }

        $resources = pmssResolveUserResources($thisUser, $policyDefaults);
        $thisUserConfig = pmssRenderLighttpdConfig(
            $template,
            $thisUser,
            $serverPort,
            $rclonePort,
            $qbittorrentPort,
            $resources
        );
        file_put_contents("/home/{$thisUser}/.lighttpd.conf", $thisUserConfig);
        passthru("chown {$thisUser}:{$thisUser} /home/{$thisUser}/.lighttpd.conf; chmod 741 /home/{$thisUser}/.lighttpd.conf");

        $phpIniPath = "/home/{$thisUser}/.lighttpd/php.ini";
        if (!file_exists($phpIniPath)) {
            passthru("cp -p /etc/skel/.lighttpd/php.ini {$phpIniPath}");
        }
        pmssUpdatePhpIni($phpIniPath, $resources['memoryLimit']);
        passthru("chown {$thisUser}:{$thisUser} {$phpIniPath}; chmod 751 {$phpIniPath}");

        echo sprintf(
            "[user:%s] lighttpd configured (port=%d, php_memory=%dM, max-procs=%d, children=%d, cpu-quota=%d%%)\n",
            $thisUser,
            $serverPort,
            $resources['memoryLimit'],
            $resources['maxProcs'],
            $resources['children'],
            $resources['cpuQuotaPercent']
        );
    }

    return 0;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(pmssUserConfigLighttpdMain($argv));
}
