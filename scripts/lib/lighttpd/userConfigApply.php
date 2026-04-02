<?php
/**
 * Per-user lighttpd configuration helpers and application helper.
 *
 * Extracted from scripts/util/userConfigLighttpd.php to keep the entrypoint
 * small while preserving output, ordering, and on-disk effects.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../systemdSliceProperties.php';
require_once __DIR__.'/userFileWrite.php';

function pmssClampLighttpdBandwidthLimits(string $config): string
{
    // lighttpd enforces uint16 for kbytes-per-second; overflow breaks startup on newer releases.
    $pattern = '/^(\\s*(?:connection|server)\\.kbytes-per-second\\s*=\\s*)(\\d+)(\\s*(?:#.*)?)$/m';
    $clamped = preg_replace_callback(
        $pattern,
        function (array $matches): string {
            $value = (int)$matches[2];
            if ($value > 65535) {
                $value = 0;
            }
            return $matches[1].$value.$matches[3];
        },
        $config
    );

    return is_string($clamped) ? $clamped : $config;
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
    $policy = <<<LIGHTTPD
\$HTTP["url"] =~ "^/webdav-{$user}/www(\$|/)" {
    webdav.is-readonly = "%s"
}
LIGHTTPD;
    if (file_exists($marker)) {
        return sprintf($policy, 'disable');
    }

    return sprintf($policy, 'enable').<<<LIGHTTPD

\$HTTP["url"] =~ "^/webdav-{$user}/www/public(\$|/)" {
    webdav.is-readonly = "disable"
}
LIGHTTPD;
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

function pmssParseSizeToMiB($value): ?int
{
    $raw = trim((string)$value);
    if ($raw === '' || $raw === 'infinity' || $raw === '0') {
        return null;
    }

    if (preg_match('/^([0-9.]+)\s*([KMG])?B?$/i', $raw, $m) !== 1) {
        return is_numeric($raw)
            ? (int) round(((float) $raw) / 1048576)
            : null;
    }

    $factors = ['' => 1 / 1048576, 'k' => 1 / 1024, 'm' => 1, 'g' => 1024];
    $unit = strtolower($m[2] ?? '');
    return isset($factors[$unit]) ? (int)round(((float) $m[1]) * $factors[$unit]) : null;
}

function pmssClampMemoryLimit(int $memoryMiB): int
{
    return max(PMSS_PHP_MEMORY_MIN_MB, min(PMSS_PHP_MEMORY_MAX_MB, $memoryMiB));
}

function pmssExtractCpuQuotaPercent(array $props, array $policyDefaults): int
{
    $quota = (int) (pmssSystemdCpuQuotaPercent($props) ?? 0);

    // When quota is explicitly set and not a legacy 85% sentinel, use it as-is.
    if ($quota > 0 && $quota !== 85) {
        return $quota;
    }

    // Fallback if no usable systemd property is found:
    // Use policy default when it is a concrete value other than the legacy 85%.
    $policyQuota = (isset($policyDefaults['cpuQuotaPercent']) && is_numeric($policyDefaults['cpuQuotaPercent']))
        ? (int)$policyDefaults['cpuQuotaPercent']
        : 0;
    if ($policyQuota > 0 && $policyQuota !== 85) {
        return $policyQuota;
    }

    // Legacy 85% (either from slice or policy) and "no quota" fall through to a
    // host-based default: ~85% per logical CPU thread, but never below 200%.
    return max(200, pmssTotalCpuThreads() * 85);
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

function pmssShouldConfigureLighttpdForHome(string $homeDir): bool
{
    return is_dir($homeDir)
        && !is_link($homeDir)
        && !is_dir($homeDir.'/www-disabled')
        && is_dir($homeDir.'/www')
        && file_exists($homeDir.'/.rtorrent.rc');
}

/**
 * Build the FastCGI socket list the watchdog must probe for a user.
 *
 * PMSS historically probed only `php.socket-0`. Keep that as the fallback when
 * the rendered config is unavailable, while using `min-procs` to decide how
 * many numbered sockets must exist immediately after startup.
 *
 * @return array<int, string>
 */
function pmssLighttpdWatchdogSocketPaths(string $homeDir, string $configPath): array
{
    $baseSocketPath = rtrim($homeDir, '/').'/.lighttpd/php.socket';
    $config = (string) @file_get_contents($configPath);
    $maxProcs = preg_match('/"max-procs"\s*=>\s*([0-9]+)/', $config, $matches) === 1
        ? (int) $matches[1]
        : 0;
    $minProcs = preg_match('/"min-procs"\s*=>\s*([0-9]+)/', $config, $matches) === 1
        ? (int) $matches[1]
        : 0;

    if ($maxProcs === 1) {
        return [$baseSocketPath];
    }

    if ($maxProcs <= 0) {
        return [$baseSocketPath.'-0'];
    }

    $expectedSockets = min($maxProcs, $minProcs > 0 ? $minProcs : $maxProcs);

    return array_map(static function ($index) use ($baseSocketPath) {
        return $baseSocketPath.'-'.$index;
    }, range(0, $expectedSockets - 1));
}

function pmssPrepareLighttpdUserDirectories(string $user, string $homeDir, bool $deflateEnabled): bool
{
    if (!pmssValidateUsername($user) || !is_dir($homeDir) || is_link($homeDir)) {
        return false;
    }

    $directories = [
        '.lighttpd'          => 0751,
        '.lighttpd/custom.d' => 0750,
        '.lighttpd/upload'   => 0751,
    ];
    if ($deflateEnabled) {
        $directories['.lighttpd/compress'] = 0751;
    }
    $directories['www/public'] = 0751;
    foreach ($directories as $directory => $mode) {
        if (!pmssEnsureUserHomeDir($user, $homeDir, $directory, $mode)) {
            return false;
        }
    }

    // Ensure the optional user-controlled include exists so lighttpd start doesn't fail.
    $customFile = $homeDir.'/.lighttpd/custom';
    if (is_link($customFile) || (file_exists($customFile) && !is_file($customFile))) {
        return false;
    }

    return file_exists($customFile) || pmssWriteUserFile($customFile, '', $user, 0751);
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
        // Clear stat cache so subsequent checks see the new lock file.
        clearstatcache(true, $lockFile);
    }
    if (!is_file($lockFile)) {
        return;
    }
    @chmod($lockFile, 0600);
    clearstatcache(true, $lockFile);

    if (function_exists('posix_geteuid') && @posix_geteuid() === 0) {
        @chown($lighttpdDir, $user);
        @chgrp($lighttpdDir, $user);
        @chown($lockFile, $user);
        @chgrp($lockFile, $user);
    }
}

// Deluge web.conf parsing and writing helpers stay here because only the
// lighttpd apply flow consumes them at runtime.
function pmssDelugeSessionsListDetected(string $raw): bool
{
    return preg_match('/"sessions"\\s*:\\s*\\[\\s*\\]/', $raw) === 1;
}

function pmssDelugeNormalizeEmptySessionsObject(array &$config): bool
{
    if (!array_key_exists('sessions', $config) || !is_array($config['sessions']) || count($config['sessions']) !== 0) {
        return false;
    }

    $config['sessions'] = (object) [];
    return true;
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

    $length = strlen($raw);
    $start = strspn($raw, " \t\n\r");
    if ($start >= $length || $raw[$start] !== '{') {
        return null;
    }

    $depth = 0;
    $inString = false;
    $escape = false;
    $firstObjectEnd = null;
    for ($index = $start; $index < $length; $index++) {
        $ch = $raw[$index];
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
                $firstObjectEnd = $index + 1;
                break;
            }
        }
    }
    if ($firstObjectEnd === null
        || !is_array($meta = json_decode(substr($raw, $start, $firstObjectEnd - $start), true))
        || !is_array($config = json_decode(ltrim(substr($raw, $firstObjectEnd)), true))) {
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

    return pmssWriteUserFile($path, $metaJson.$configJson, $owner, $mode);
}

// Reverse proxy fragments stay with the lighttpd apply flow because the
// runtime caller is the per-user config writer.
function pmssDelugeLighttpdProxyFragment(string $user, int $webPort): string
{
    return <<<LIGHTTPD
# PMSS-managed: Deluge reverse proxy.
# Legacy path /deluge-{$user}/ kept for compatibility until at least 2028-01-28.

\$HTTP["url"] =~ "^/user-{$user}/deluge($|/)" {
  auth.require = ()
  proxy.server = ( "" => ( (
    "host" => "127.0.0.1",
    "port" => {$webPort}
  ) ) ),
  proxy.header = (
      "map-urlpath" => (
         "/user-{$user}/deluge/"  => "/",
         "/user-{$user}/deluge" => ""
       )
  )
}

\$HTTP["url"] =~ "^/deluge-{$user}($|/)" {
  auth.require = ()
  proxy.server = ( "" => ( (
    "host" => "127.0.0.1",
    "port" => {$webPort}
  ) ) ),
  proxy.header = (
      "map-urlpath" => (
         "/deluge-{$user}/"  => "/user-{$user}/deluge/",
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
  auth.require = ()
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

function pmssInvidiousLighttpdProxyFragment(string $user, int $port): string
{
    return <<<LIGHTTPD
# PMSS-managed: Invidious reverse proxy.

\$HTTP["url"] =~ "^/public-{$user}/invidious($|/)" {
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
         "/public-{$user}/invidious/"  => "/",
         "/public-{$user}/invidious" => ""
       )
  )
}

\$HTTP["url"] =~ "^/user-{$user}/apps/invidious($|/)" {
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
         "/user-{$user}/apps/invidious/"  => "/",
         "/user-{$user}/apps/invidious" => ""
       )
  )
}

LIGHTTPD;
}

function pmssUserConfigLighttpdConfigureUser(
    string $thisUser,
    string $portsDirectory,
    $template,
    bool $deflateEnabled,
    array $policyDefaults
): void {
    if (!pmssValidateUsername($thisUser)) {
        fwrite(STDERR, "Skipping invalid username: ".substr($thisUser, 0, 20)."\n");
        return;
    }

    $homeDir = "/home/{$thisUser}";
    if (!pmssShouldConfigureLighttpdForHome($homeDir)) {
        return;
    }

    $portFile = "{$portsDirectory}/lighttpd-{$thisUser}";
    $serverPort = file_exists($portFile)
        ? pmssReadRegularFileInt($portFile)
        : (int) trim((string) shell_exec('/scripts/util/portManager.php assign '.escapeshellarg($thisUser).' lighttpd'));

    // Prepare directories and defaults.
    if (!pmssPrepareLighttpdUserDirectories($thisUser, $homeDir, $deflateEnabled)) {
        fwrite(STDERR, "[user:{$thisUser}] lighttpd directory preparation failed; skipping\n");
        return;
    }
    pmssEnsureWebdavLockDatabase($thisUser, $homeDir);
    $customDir = $homeDir.'/.lighttpd/custom.d';

    $proxyPortFiles = [
        'rclone' => $homeDir.'/.rclonePort',
        'qbittorrent' => $homeDir.'/.qbittorrentPort',
    ];
    $proxyPorts = [];
    foreach ($proxyPortFiles as $proxyName => $proxyPortFile) {
        $proxyPort = pmssReadRegularFileInt($proxyPortFile);
        if ($proxyPort < 1024 || $proxyPort > 65500) {
            file_put_contents($proxyPortFile, $proxyPort = (int) round(rand(1500, 65500)));
        }
        $proxyPorts[$proxyName] = $proxyPort;
    }
    $rclonePort = $proxyPorts['rclone'];
    $qbittorrentPort = $proxyPorts['qbittorrent'];

    // PMSS-managed proxy fragments under ~/.lighttpd/custom.d/
    foreach ([
        'rclone' => pmssRcloneLighttpdProxyFragment($thisUser, $rclonePort),
        'qbittorrent' => pmssQbittorrentLighttpdProxyFragment($thisUser, $qbittorrentPort),
    ] as $proxyName => $proxyFragment) {
        $proxyConfPath = "{$customDir}/pmss-{$proxyName}.conf";
        if (!pmssWriteUserFile($proxyConfPath, $proxyFragment, $thisUser, 0640)) {
            fwrite(STDERR, "[user:{$thisUser}] Failed to write {$proxyName} lighttpd fragment\n");
        }
    }

    // Optional Invidious proxy wiring: publish both public and private URLs
    // only when the user or installer pins a local port explicitly.
    $invidiousPort = pmssReadRegularFileInt($homeDir.'/.invidiousPort');
    $invidiousConfPath = $customDir.'/pmss-invidious.conf';
    if ($invidiousPort >= 1024 && $invidiousPort <= 65535) {
        if (!pmssWriteUserFile($invidiousConfPath, pmssInvidiousLighttpdProxyFragment($thisUser, $invidiousPort), $thisUser, 0640)) {
            fwrite(STDERR, "[user:{$thisUser}] Failed to write invidious lighttpd fragment\n");
        }
    } elseif (is_file($invidiousConfPath) || is_link($invidiousConfPath)) {
        @unlink($invidiousConfPath);
    }

    // Deluge: generate a per-user proxy fragment under ~/.lighttpd/custom.d/
    // so nginx stays a lightweight reverse proxy.
    $delugeWebPort = null;
    $delugeWebConfPath = $homeDir.'/.config/deluge/web.conf';
    if (is_readable($delugeWebConfPath)) {
        $delugeRaw = @file_get_contents($delugeWebConfPath);
        $needsDelugeWebConfWrite = is_string($delugeRaw) && pmssDelugeSessionsListDetected($delugeRaw);
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
                $needsDelugeWebConfWrite = true;
            }

            if ($needsDelugeWebConfWrite) {
                // Deluge expects sessions as a dict/object; writing an empty PHP array
                // would serialize as [] and break login session creation.
                pmssDelugeNormalizeEmptySessionsObject($delugeParsed['config']);
                if (pmssDelugeWriteWebConf($delugeWebConfPath, $delugeParsed['meta'], $delugeParsed['config'], $thisUser)) {
                    // Apply web.conf changes on the next cron tick (checkDelugeInstances.php).
                    passthru('killall -u '.escapeshellarg($thisUser).' -TERM deluge-web 2>/dev/null || true');
                }
            }
        }
    }
    if ($delugeWebPort === null) {
        // Fallback (legacy hosts): derive deluge-web port from .delugePort.
        $delugePort = pmssReadRegularFileInt($homeDir.'/.delugePort');
        if ($delugePort >= 1024 && $delugePort <= 65535) {
            foreach ([$delugePort + 1, $delugePort] as $candidate) {
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
        $delugeConfPath = $customDir.'/pmss-deluge.conf';
        if (!pmssWriteUserFile($delugeConfPath, pmssDelugeLighttpdProxyFragment($thisUser, $delugeWebPort), $thisUser, 0640)) {
            fwrite(STDERR, "[user:{$thisUser}] Failed to write deluge lighttpd fragment\n");
        }
    }

    $props = pmssReadUserSlicePropertiesByUsername(
        $thisUser,
        ['MemoryHigh', 'MemoryMax', 'CPUQuotaPerSecUSec', 'CPUQuotaPeriodUSec', 'CPUQuota']
    );
    $memoryHigh = null;
    foreach (['MemoryHigh', 'MemoryMax'] as $memoryLimitField) {
        if (isset($props[$memoryLimitField]) && ($memoryHigh = pmssParseSizeToMiB($props[$memoryLimitField])) !== null) {
            break;
        }
    }
    $cpuQuotaPercent = pmssExtractCpuQuotaPercent($props, $policyDefaults);
    $plan = pmssComputePhpProcessPlan($cpuQuotaPercent);
    $resources = [
        'memoryLimit'     => pmssClampMemoryLimit((int) ($memoryHigh ?? (isset($policyDefaults['memoryHighMiB']) ? (int) $policyDefaults['memoryHighMiB'] : 512))),
        'cpuQuotaPercent' => $cpuQuotaPercent,
        'maxProcs'        => $plan['max_procs'],
        'children'        => $plan['children'],
        'totalThreads'    => $plan['totalThreads'],
    ];
    $thisUserConfig = str_replace(
        array("##username", "##serverPort", "##rclonePort", "##qbittorrentPort", "##PMSS_WEBDAV_WWW_POLICY##"),
        array($thisUser, $serverPort, $rclonePort, $qbittorrentPort, pmssWebdavWwwPolicyBlock($thisUser)),
        $template
    );
    $thisUserConfig = preg_replace(
        ['/("max-procs"\\s*=>\\s*)[0-9]+/', '/("PHP_FCGI_CHILDREN"\\s*=>\\s*")[0-9]+(")/'],
        ['${1}'.$resources['maxProcs'], '${1}'.$resources['children'].'${2}'],
        $thisUserConfig,
        1
    );
    $thisUserConfig = pmssClampLighttpdBandwidthLimits($thisUserConfig);
    if (!pmssWriteUserFile($homeDir.'/.lighttpd.conf', $thisUserConfig, $thisUser, 0741)) {
        fwrite(STDERR, "[user:{$thisUser}] Failed to write .lighttpd.conf; skipping user\n");
        return;
    }

    $phpIniPath = $homeDir.'/.lighttpd/php.ini';
    if (is_link($phpIniPath) || (file_exists($phpIniPath) && !is_file($phpIniPath))) {
        fwrite(STDERR, "[user:{$thisUser}] Refusing to operate on unsafe php.ini path; skipping user\n");
        return;
    }
    if (!file_exists($phpIniPath)) {
        $skelPhpIni = @file_get_contents('/etc/skel/.lighttpd/php.ini');
        if (!is_string($skelPhpIni) || !pmssWriteUserFile($phpIniPath, $skelPhpIni, $thisUser, 0751)) {
            fwrite(STDERR, "[user:{$thisUser}] Failed to seed php.ini; skipping user\n");
            return;
        }
    }
    if (!is_link($phpIniPath) && is_file($phpIniPath) && is_string($phpIniContent = @file_get_contents($phpIniPath))) {
        $memoryLine = 'memory_limit = '.$resources['memoryLimit'].'M';
        if (preg_match('/^memory_limit\s*=.*$/m', $phpIniContent)) {
            $phpIniContent = preg_replace('/^memory_limit\s*=.*$/m', $memoryLine, $phpIniContent, 1);
        } else {
            $phpIniContent = rtrim($phpIniContent, "\n")."\n".$memoryLine."\n";
        }
        pmssAtomicWriteFile($phpIniPath, $phpIniContent);
    }
    @chmod($phpIniPath, 0751);
    if (function_exists('posix_geteuid') && @posix_geteuid() === 0) {
        @chown($phpIniPath, $thisUser);
        @chgrp($phpIniPath, $thisUser);
    }

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
