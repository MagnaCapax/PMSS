<?php
/** Build-aware upload throttling; daemon-owned settings are written only while stopped. */
require_once __DIR__.'/qbittorrentConfig.php';
require_once __DIR__.'/qbittorrentBuild.php';
require_once dirname(__DIR__).'/runtime/environment.php';

/** Use the discovered build, falling back to upstream migration 2 only when unknown. */
function pmssQbittorrentThrottleKey(string $config, ?string $build): array
{
    $build = pmssQbittorrentBuildNumber($build);
    $migration = trim(pmssQbittorrentConfigValues($config)['Meta']['MigrationVersion'] ?? '', " \t\"");
    $modern = $build !== null ? version_compare($build, '4.4', '>=')
        : preg_match('/\A[0-9]+\z/', $migration) === 1 && (int) $migration >= 2;
    return $modern ? ['BitTorrent', 'Session\\GlobalUPSpeedLimit'] : ['Preferences', 'Connection\\GlobalUPLimit'];
}

/** Decimal caps and applied records must fit a PHP integer without saturation. */
function pmssQbittorrentThrottleNumber(string $value): ?int
{
    return pmssUnsignedDecimalIntParse(trim($value));
}

/** #1165: customer limits survive absent PMSS caps and stricter choices stay compliant. */
function pmssQbittorrentThrottleDiffers(string $config, ?int $throttle, ?string $build, ?int $applied = null): bool
{
    if (($throttle ?? 0) <= 0 && $applied === null) return false;
    // Read only the selected build's limit; invalid/unlimited values need an active cap.
    list($section, $key) = pmssQbittorrentThrottleKey($config, $build);
    $value = pmssQbittorrentThrottleNumber(trim(pmssQbittorrentConfigValues($config)[$section][$key] ?? '0', " \t\""));
    return ($throttle ?? 0) > 0 ? ($value === null || $value === 0 || $value > $throttle)
        : $value === $applied;
}

/** Write the build's key; exact mode retains the pre-ADR-0182 helper contract. */
function pmssQbittorrentThrottleContent(string $config, ?int $throttle, ?string $build, ?int $applied = null, bool $exact = false): string
{
    if (!$exact && !pmssQbittorrentThrottleDiffers($config, $throttle, $build, $applied)) return $config;
    $values = pmssQbittorrentConfigValues($config);
    if ($throttle !== null && $throttle > 0) {
        list($section, $key) = pmssQbittorrentThrottleKey($config, $build);
        $updated = pmssQbittorrentConfigSet($config, $section, $key, (string) $throttle, true);
        // A second pre-start restore must not copy a stale, already-present alias over this cap.
        list($otherSection, $otherKey) = $section === 'BitTorrent'
            ? ['Preferences', 'Connection\\GlobalUPLimit'] : ['BitTorrent', 'Session\\GlobalUPSpeedLimit'];
        // Unknown legacy/uninitialized builds need both schemas seeded before first start.
        $seedBoth = pmssQbittorrentBuildNumber($build) === null && $section === 'Preferences';
        if ($seedBoth || ((!$exact || $updated !== $config) && array_key_exists($otherKey, $values[$otherSection] ?? [])))
            $updated = pmssQbittorrentConfigSet($updated, $otherSection, $otherKey, (string) $throttle, true);
        return $updated;
    }
    // Historical explicit removal writes modern unlimited zero and preserves a saved zero.
    if ($exact && isset($values['BitTorrent']['Session\\GlobalUPSpeedLimit'])
        && pmssQbittorrentThrottleNumber(trim($values['BitTorrent']['Session\\GlobalUPSpeedLimit'], " \t\"")) !== 0)
        $config = pmssQbittorrentConfigSet($config, 'BitTorrent', 'Session\\GlobalUPSpeedLimit', '0', true);
    list($lines, $trailing, $ending) = pmssQbittorrentConfigSplitLines($config);
    $section = '';
    $result = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*\[([^\]]+)\]\s*$/', $line, $match) === 1) $section = $match[1];
        if (preg_match('/^\s*([^=]+?)\s*=/', $line, $match) === 1
            && (($section === 'BitTorrent' && $match[1] === 'Session\\GlobalUPSpeedLimit')
                || ($section === 'Preferences' && $match[1] === 'Connection\\GlobalUPLimit'))
            && ($exact ? ($section === 'Preferences'
                    && pmssQbittorrentThrottleNumber(trim($values[$section][$match[1]] ?? '0', " \t\"")) !== 0)
                : preg_match('/=\s*"?0*'.preg_quote((string) $applied, '/').'"?\s*$/', $line) === 1)) continue;
        $result[] = $line;
    }
    return pmssQbittorrentConfigJoinLines($result, $trailing, $ending);
}

/** Preserve the original exact-value renderer using the single shared transformer. */
function pmssQbittorrentUploadThrottleConfig(string $config, ?int $throttle, ?string $build = null): ?string
{
    if (!is_array(@parse_ini_string($config, true, INI_SCANNER_RAW))) return null;
    return pmssQbittorrentThrottleContent($config, $throttle, $build, null, true);
}

/** Compare without writing; the account reads its own config, never root (ADR 0182). */
function pmssQbittorrentUploadThrottleNeedsUpdate(string $username): bool
{
    require_once __DIR__.'/qbittorrentThrottleAccount.php';
    $home = dirname(dirname(dirname(pmssQbittorrentConfigPath($username))));
    return pmssQbittorrentThrottleAsUser($username, $home, pmssReadTorrentThrottle($username), false, true) === true;
}

/** Preserve the historical changed/unchanged result; live daemons defer to the watchdog. */
function pmssQbittorrentApplyUploadThrottle(string $username, ?int $throttle = null): bool
{
    require_once __DIR__.'/qbittorrentThrottleAccount.php';
    $throttle = $throttle ?? pmssReadTorrentThrottle($username);
    $home = dirname(dirname(dirname(pmssQbittorrentConfigPath($username))));
    return pmssQbittorrentThrottleAsUser($username, $home, $throttle, true, true) === true;
}

/** Track a provisioning template cap even if selected-build convergence needs no write. */
function pmssQbittorrentThrottleSeedRecord(string $username, string $home, int $throttle): bool
{
    if ($throttle <= 0) return true;
    require_once __DIR__.'/directories.php';
    return pmssEnsureUserHomeDir($username, $home, '.config/pmss/torrentClients', 0700)
        && pmssWriteUserFile($home.'/.config/pmss/torrentClients/qbittorrent.throttleApplied',
            $throttle."\n", $username, 0600);
}
