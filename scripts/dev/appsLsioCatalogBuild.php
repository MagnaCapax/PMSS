<?php
/** Build the delivered, documentation-only LinuxServer.io app catalog. */
require_once dirname(__DIR__).'/lib/log.php';
require_once __DIR__.'/../lib/runtime/filesystem.php';
const PMSS_LSIO_SOURCE = 'https://api.linuxserver.io/api/v1/images?include_config=false';
const PMSS_LSIO_EXCLUDED = array(
    // PMSS already provides these applications: media stack (jellyfin, sonarr, radarr, prowlarr, sabnzbd),
    // native clients (qbittorrent, deluge), /opt builds (lidarr), docker-install-lsio (mariadb, phpmyadmin),
    // native installers (resilio-sync, wireguard). Add a name here only with the PMSS path that provides it.
    'jellyfin', 'sonarr', 'radarr', 'prowlarr', 'sabnzbd', 'qbittorrent', 'deluge', 'lidarr', 'mariadb', 'phpmyadmin', 'resilio-sync', 'wireguard',
    // Circumvention-specific application.
    'oscam',
    // Shared-host abuse risk: long-running CPU donation, and payload delivery built to deceive downloaders.
    'boinc', 'foldingathome', 'pwndrop',
);

/** Reduce upstream Markdown to short, plain display copy. */
function pmssLsioDescription(string $value): string
{
    $value = preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $value);
    $value = preg_replace('/[\[\]`*_]/u', '', strip_tags($value));
    $value = trim(preg_replace('/\s+/u', ' ', $value));
    $sentences = preg_split('/(?<=[.!?])\s+/u', $value, 2);
    preg_match('/^.{0,120}/us', $sentences[0], $match);
    return rtrim($match[0] ?? '', " \t\n\r\0\x0B,;:-");
}

/** Keep only supported images and fixed fields needed by the panel. */
function pmssLsioCatalogBuild(array $images): array
{
    $entries = array();
    foreach ($images as $image) {
        $name = $image['name'] ?? '';
        if (!is_string($name) || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name)
            || in_array($name, PMSS_LSIO_EXCLUDED, true)
            || ($image['deprecated'] ?? true) !== false || ($image['stable'] ?? false) !== true) continue;
        $description = (string) ($image['description'] ?? '');
        $title = preg_match('/^\[([^\]]+)\]/u', $description, $match) ? $match[1] : $name;
        $entries[] = array('name' => $name, 'title' => $title,
            'description' => pmssLsioDescription($description), 'category' => (string) ($image['category'] ?? ''),
            'image' => 'lscr.io/linuxserver/'.$name,
            'guide' => 'https://docs.linuxserver.io/images/docker-'.$name.'/');
    }
    usort($entries, static function (array $a, array $b): int {
        return strcasecmp($a['category'], $b['category']) ?: strcasecmp($a['title'], $b['title']);
    });
    return $entries;
}

/** Store source fields only; the standalone snapshot expands name-derived links. */
function pmssLsioCatalogExport(array $entries, string $snapshotDate): string
{
    foreach ($entries as &$entry) {
        unset($entry['image'], $entry['guide']);
    }
    unset($entry);
    $array = preg_replace('/[ \t]+$/m', '', var_export($entries, true));
    $projection = <<<'PHP'
return array_map(static function (array $app): array {
    $app['image'] = 'lscr.io/linuxserver/'.$app['name'];
    $app['guide'] = 'https://docs.linuxserver.io/images/docker-'.$app['name'].'/';
    return $app;
},
PHP;
    return "<?php\n/** Source: ".PMSS_LSIO_SOURCE."; snapshot: ".$snapshotDate.". */\n"
        .$projection.$array.");\n";
}

/** Publish a complete catalog from the target directory, retaining its public read mode. */
function pmssLsioCatalogWrite(string $target, string $output): bool
{
    $temporary = @tempnam(dirname($target), '.pmss-lsio-');
    if ($temporary === false) {
        return false;
    }

    // A short write or failed mode change must leave the previous snapshot intact.
    $ready = pmssFileWriteComplete($temporary, $output) && @chmod($temporary, 0644);
    if (!$ready || !@rename($temporary, $target)) {
        @unlink($temporary);
        return false;
    }
    return true;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $source = $argv[1] ?? PMSS_LSIO_SOURCE;
    $json = @file_get_contents($source);
    $data = is_string($json) ? pmssJsonDecodeAssoc($json) : null;
    if ($data === null || !isset($data['data']['repositories']['linuxserver'])
        || !is_array($data['data']['repositories']['linuxserver'])) {
        fwrite(STDERR, "Could not read LinuxServer.io image catalog: {$source}\n");
        exit(1);
    }
    $entries = pmssLsioCatalogBuild($data['data']['repositories']['linuxserver']);
    $output = pmssLsioCatalogExport($entries, date('Y-m-d'));
    $target = dirname(__DIR__, 2).'/etc/skel/www/appsLsioCatalog.php';
    if (!pmssLsioCatalogWrite($target, $output)) {
        fwrite(STDERR, "Could not write {$target}\n");
        exit(1);
    }
    fwrite(STDOUT, count($entries)." entries written to {$target}\n");
}
