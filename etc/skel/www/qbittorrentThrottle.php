<?php
/** Build-aware upload throttling; daemon-owned settings are written only while stopped. */
require_once __DIR__.'/qbittorrentConfig.php';
require_once __DIR__.'/qbittorrentBuild.php';

/** Use the discovered build, falling back to upstream migration 2 only when unknown. */
function pmssQbittorrentFrontendThrottleKey(string $config, ?string $build): array
{
    $build = pmssQbittorrentFrontendBuildNumber($build);
    $migration = trim(pmssQbittorrentFrontendConfigValues($config)['Meta']['MigrationVersion'] ?? '', " \t\"");
    $modern = $build !== null ? version_compare($build, '4.4', '>=')
        : preg_match('/\A[0-9]+\z/', $migration) === 1 && (int) $migration >= 2;
    return $modern ? ['BitTorrent', 'Session\\GlobalUPSpeedLimit'] : ['Preferences', 'Connection\\GlobalUPLimit'];
}

/** Decimal caps and applied records must fit a PHP integer without saturation. */
function pmssQbittorrentFrontendThrottleNumber(string $value): ?int
{
    $value = trim($value);
    if (preg_match('/\A[0-9]+\z/', $value) !== 1) return null;
    $digits = ltrim($value, '0') ?: '0';
    if (strlen($digits) > strlen((string) PHP_INT_MAX)
        || (strlen($digits) === strlen((string) PHP_INT_MAX) && strcmp($digits, (string) PHP_INT_MAX) > 0)) return null;
    return (int) $digits;
}

/** #1165: customer limits survive absent PMSS caps and stricter choices stay compliant. */
function pmssQbittorrentFrontendThrottleDiffers(string $config, ?int $throttle, ?string $build, ?int $applied = null): bool
{
    if (($throttle ?? 0) <= 0 && $applied === null) return false;
    // Read only the selected build's limit; invalid/unlimited values need an active cap.
    list($section, $key) = pmssQbittorrentFrontendThrottleKey($config, $build);
    $value = pmssQbittorrentFrontendThrottleNumber(trim(pmssQbittorrentFrontendConfigValues($config)[$section][$key] ?? '0', " \t\""));
    return ($throttle ?? 0) > 0 ? ($value === null || $value === 0 || $value > $throttle)
        : $value === $applied;
}

/** #1165: write the key this build reads, never a legacy alias that exit-save discards. */
function pmssQbittorrentFrontendThrottleContent(string $config, ?int $throttle, ?string $build, ?int $applied = null): string
{
    if (!pmssQbittorrentFrontendThrottleDiffers($config, $throttle, $build, $applied)) return $config;
    if ($throttle !== null && $throttle > 0) {
        list($section, $key) = pmssQbittorrentFrontendThrottleKey($config, $build);
        $updated = pmssQbittorrentFrontendConfigSet($config, $section, $key, (string) $throttle, true);
        // A second pre-start restore must not copy a stale, already-present alias over this cap.
        list($otherSection, $otherKey) = $section === 'BitTorrent'
            ? ['Preferences', 'Connection\\GlobalUPLimit'] : ['BitTorrent', 'Session\\GlobalUPSpeedLimit'];
        // Unknown legacy/uninitialized builds need both schemas seeded before first start.
        $seedBoth = pmssQbittorrentFrontendBuildNumber($build) === null && $section === 'Preferences';
        if ($seedBoth || array_key_exists($otherKey, pmssQbittorrentFrontendConfigValues($config)[$otherSection] ?? []))
            $updated = pmssQbittorrentFrontendConfigSet($updated, $otherSection, $otherKey, (string) $throttle, true);
        return $updated;
    }
    list($lines, $trailing, $ending) = pmssQbittorrentFrontendConfigSplitLines($config);
    $section = '';
    $result = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*\[([^\]]+)\]\s*$/', $line, $match) === 1) $section = $match[1];
        if (preg_match('/^\s*([^=]+?)\s*=/', $line, $match) === 1
            && (($section === 'BitTorrent' && $match[1] === 'Session\\GlobalUPSpeedLimit')
                || ($section === 'Preferences' && $match[1] === 'Connection\\GlobalUPLimit'))
            && preg_match('/=\s*"?0*'.preg_quote((string) $applied, '/').'"?\s*$/', $line) === 1) continue;
        $result[] = $line;
    }
    return pmssQbittorrentFrontendConfigJoinLines($result, $trailing, $ending);
}
