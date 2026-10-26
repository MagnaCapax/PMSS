<?php
/** Raw QSettings WebUI access; mirrored in the customer tree (ADR 0016). */

/** Read raw values without interpreting Qt byte arrays, escapes or list syntax. */
function pmssQbittorrentFrontendConfigValues(string $config): array
{
    $values = [];
    $section = '';
    foreach (preg_split('/\r\n|\n|\r/', $config) as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || $trimmed[0] === ';' || $trimmed[0] === '#') continue;
        if (preg_match('/^\s*\[([^\]]+)\]\s*$/', $line, $match) === 1) {
            $section = $match[1];
        } elseif (preg_match('/^\s*([^;#=][^=]*?)\s*=(.*)$/', $line, $match) === 1) {
            $values[$section][trim($match[1])] = $match[2];
        }
    }
    return $values;
}

/** A 5.3+ build reads WebUI only after migration 11 has actually moved its settings. */
function pmssQbittorrentFrontendWebUiSection(string $config, ?string $build = null): array
{
    if (is_string($build) && preg_match('/\A([0-9]+\.[0-9]+(?:\.[0-9]+)?)(?:-lt(?:1\.2|2\.[01]))?\z/', $build, $match) === 1)
        if (version_compare($match[1], '5.3', '<')) return ['Preferences', 'WebUI\\'];
    $version = trim(pmssQbittorrentFrontendConfigValues($config)['Meta']['MigrationVersion'] ?? '');
    if (preg_match('/\A"([0-9]+)"\z/', $version, $quoted) === 1) $version = $quoted[1];
    $digits = ltrim($version, '0');
    $modern = preg_match('/\A[0-9]+\z/', $version) === 1
        && (strlen($digits) > 2 || strlen($digits) === 2 && strcmp($digits, '11') >= 0);
    return $modern ? ['WebUI', ''] : ['Preferences', 'WebUI\\'];
}

/** Read only the authoritative section, never a stale compatibility value. */
function pmssQbittorrentFrontendWebUiValue(string $config, string $key, ?string $build = null): ?string
{
    list($section, $prefix) = pmssQbittorrentFrontendWebUiSection($config, $build);
    return pmssQbittorrentFrontendConfigValues($config)[$section][$prefix.$key] ?? null;
}
