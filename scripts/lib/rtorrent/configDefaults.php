<?php
/** rTorrent configuration source acquisition; no account writes or port allocation. @license GPL-3.0-only */

require_once dirname(__DIR__).'/log.php';

/** Resolve injected or stored resources and apply the legacy shallow defaults. */
function pmssRtorrentConfigResources(array $config = [], string $path = '/etc/seedbox/config/rtorrent.resources.json'): array
{
    if ($config === []) {
        $contents = @file_get_contents($path);
        if ($contents === false) throw new RuntimeException('Unable to read rTorrent resource config: '.$path);
        if (($config = pmssJsonDecodeAssoc($contents)) === null) throw new RuntimeException('Invalid rTorrent resource config JSON in '.$path);
    }
    return $config + ['ramBlock' => 250, 'peers' => ['minimum' => 6, 'maximum' => 32], 'uploadSlots' => 7];
}

/** Prefer the nonempty legacy override, preserving template bytes and the canonical failure path. */
function pmssRtorrentConfigTemplate($template = null, array $paths = ['/etc/seedbox/config/template.rtorrentrc', '/etc/seedbox/config/template.rtorrent.rc']): string
{
    if (is_string($template) && trim($template) !== '') return $template;
    foreach ($paths as $path) {
        if (!is_file($path)) continue;
        $contents = @file_get_contents($path);
        if ($contents !== false && trim($contents) !== '') return $contents;
    }
    throw new RuntimeException('Unable to read rTorrent template: /etc/seedbox/config/template.rtorrent.rc');
}
