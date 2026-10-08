<?php
/** Build the delivered, documentation-only LinuxServer.io app catalog. */
const PMSS_LSIO_SOURCE = 'https://api.linuxserver.io/api/v1/images?include_config=false';
const PMSS_LSIO_EXCLUDED = array(
    // PMSS already provides these applications.
    'jellyfin', 'sonarr', 'radarr', 'prowlarr', 'sabnzbd', 'qbittorrent', 'deluge', 'lidarr', 'bazarr', 'mariadb', 'phpmyadmin', 'resilio-sync', 'wireguard',
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

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $source = $argv[1] ?? PMSS_LSIO_SOURCE;
    $json = @file_get_contents($source);
    $data = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($data) || !isset($data['data']['repositories']['linuxserver'])
        || !is_array($data['data']['repositories']['linuxserver'])) {
        fwrite(STDERR, "Could not read LinuxServer.io image catalog: {$source}\n");
        exit(1);
    }
    $entries = pmssLsioCatalogBuild($data['data']['repositories']['linuxserver']);
    $array = preg_replace('/[ \t]+$/m', '', var_export($entries, true));
    $output = "<?php\n/** Source: ".PMSS_LSIO_SOURCE."; snapshot: ".date('Y-m-d').". */\nreturn ".$array.";\n";
    $target = dirname(__DIR__, 2).'/etc/skel/www/appsLsioCatalog.php';
    if (file_put_contents($target, $output) !== strlen($output)) {
        fwrite(STDERR, "Could not write {$target}\n");
        exit(1);
    }
    fwrite(STDOUT, count($entries)." entries written to {$target}\n");
}
