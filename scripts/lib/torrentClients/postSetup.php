<?php
/** Reconcile migrated selections after the account home is usable. */

require_once __DIR__.'/catalog.php';
require_once __DIR__.'/projection.php';
require_once __DIR__.'/userLinks.php';
require_once dirname(__DIR__).'/user/log.php';

/** Read the host-published view, never a selection supplied by the tenant. */
function pmssTorrentClientsPostSetupAvailable(string $path): array
{
    $available = [];
    foreach (pmssTorrentClientsProjectionVersionsRead($path) as $entry) {
        if (!is_array($entry) || !is_string($entry['version'] ?? null)
            || !in_array($entry['client'] ?? null, ['qbittorrent', 'rtorrent', 'deluge', 'transmission'], true)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $entry['version']) !== 1) continue;
        $available[$entry['client']][$entry['version']] = true;
    }
    return $available;
}

/** Detect a source-store default without inspecting tenant paths as root. */
function pmssTorrentClientsPostSetupHasStoreLink(string $user, string $home, array $ops): bool
{
    $root = rtrim($ops['root'] ?? '/home/.pmss/clients', '/');
    $command = 'for client in qbittorrent rtorrent deluge transmission; do '
        .'target=$(readlink '.escapeshellarg($home).'/bin/$client/default 2>/dev/null) || continue; '
        .'case "$target" in '.escapeshellarg($root.'/').'*) exit 0;; esac; done; exit 1';
    return pmssTorrentClientsUserLinksRun($user, $home, [$home.'/bin'], $command, $ops);
}

/** Replace only an unavailable store selection; custom builds remain untouched. */
function pmssTorrentClientsPostSetupFallback(string $user, string $home, string $client, array $available, string $default, array $ops): bool
{
    $root = rtrim($ops['root'] ?? '/home/.pmss/clients', '/');
    $link = $home.'/bin/'.$client.'/default';
    $prefix = $root.'/'.$client.'/';
    $noticeDir = $home.'/.config/pmss/torrentClients';
    $notice = $noticeDir.'/'.$client.'.notice';
    $binaries = [
        'qbittorrent' => 'qbittorrent-nox', 'rtorrent' => 'rtorrent',
        'deluge' => 'deluged', 'transmission' => 'transmission-daemon',
    ];
    if (!isset($binaries[$client])) return false;
    $command = 'selected=$(readlink '.escapeshellarg($link).' 2>/dev/null) || exit 1; '
        .'case "$selected" in '.escapeshellarg($prefix).'*) ;; *) exit 1;; esac; ';
    foreach (array_keys($available[$client] ?? []) as $version) {
        if ($version === 'system') continue;
        $path = $prefix.$version;
        $command .= 'if [ "$selected" = '.escapeshellarg($path)
            .' ] && [ -d "$selected" ] && [ ! -L "$selected" ]'
            .' && [ -f "$selected/'. $binaries[$client].'" ]'
            .' && [ ! -L "$selected/'. $binaries[$client].'" ]; then exit 1; fi; ';
    }
    if ($default === 'system') {
        $command .= 'rm -f -- '.escapeshellarg($link).' || exit 1; ';
    } else {
        $target = $prefix.$default;
        $command .= 'temp='.escapeshellarg($link.'.pmss-').'$$; [ ! -e "$temp" ] && [ ! -L "$temp" ] || exit 1; '
            .'ln -s '.escapeshellarg($target).' "$temp" && mv -Tf -- "$temp" '.escapeshellarg($link)
            .' || { rm -f -- "$temp"; exit 1; }; ';
    }
    $message = $client.' selection unavailable on this host; using the host default.';
    $command .= 'mkdir -m 700 -p '.escapeshellarg($noticeDir).' || exit 1; '
        .'[ ! -L '.escapeshellarg($noticeDir).' ] && [ ! -L '.escapeshellarg($notice).' ] || exit 1; '
        .'printf '.escapeshellarg('%s\n').' '.escapeshellarg($message).' > '.escapeshellarg($notice);
    return pmssTorrentClientsUserLinksRun($user, $home, [$home.'/bin/'.$client], $command, $ops);
}

/** Shared post-permissions path for transfer and recreate's environment pass. */
function pmssTorrentClientsPostSetupConverge(string $user, string $home, array $ops = []): void
{
    $homeRoot = rtrim($ops['homeRoot'] ?? '/home', '/');
    if (!pmssValidateUsername($user) || $home !== $homeRoot.'/'.$user
        || (function_exists('pmssEnvFlagEnabled') && pmssEnvFlagEnabled('PMSS_DRY_RUN'))) return;
    $log = $ops['log'] ?? 'pmssUserLog';
    if (!pmssTorrentClientsMarkerEnabled()) {
        if (pmssTorrentClientsPostSetupHasStoreLink($user, $home, $ops)) {
            $log($user, '[WARN] Migrated torrent-client store selection left unchanged: this host has torrent clients disabled.');
        }
        return;
    }
    if (!pmssTorrentClientsUserLinksConverge($user, $home, $ops)) {
        $log($user, '[WARN] Torrent-client version links could not be converged.');
        return;
    }
    $catalog = pmssTorrentClientsCatalogLoad($ops['catalog'] ?? '/etc/seedbox/config/torrentClients.json');
    if ($catalog === []) {
        $log($user, '[WARN] Torrent-client catalog unavailable; migrated selection needs review.');
        return;
    }
    $available = pmssTorrentClientsPostSetupAvailable($ops['projection'] ?? '/var/lib/pmss/public/torrentClients.json');
    foreach ($catalog as $entry) {
        if (!$entry['isDefault']) continue;
        $client = $entry['client'];
        $default = $entry['version'];
        if ($default !== 'system' && !isset($available[$client][$default])) continue;
        if (pmssTorrentClientsPostSetupFallback($user, $home, $client, $available, $default, $ops)) {
            $log($user, '[WARN] '.$client.' selection unavailable on this host; using the host default.');
        }
    }
}
