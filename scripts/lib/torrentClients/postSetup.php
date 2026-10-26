<?php
/** Reconcile migrated selections after the account home is usable. */

require_once __DIR__.'/catalog.php';
require_once __DIR__.'/projection.php';
require_once __DIR__.'/userLinks.php';
require_once dirname(__DIR__).'/user/log.php';

/** Keep the customer notice and operator log aligned on the server fallback. */
function pmssTorrentClientsPostSetupFallbackMessage(string $client): string
{
    $labels = ['qbittorrent' => 'qBittorrent', 'rtorrent' => 'rTorrent', 'deluge' => 'Deluge', 'transmission' => 'Transmission'];
    return 'The '.$labels[$client].' version selected for this account is not installed on this server; the server\'s build is used.';
}

/** Read the host-published view, never a selection supplied by the tenant. */
function pmssTorrentClientsPostSetupAvailable(string $path): array
{
    $available = [];
    foreach (pmssTorrentClientsProjectionVersionsRead($path) as $entry) {
        if (!is_array($entry)
            || !in_array($entry['client'] ?? null, ['qbittorrent', 'rtorrent', 'deluge', 'transmission'], true)
            || !pmssTorrentClientsVersionValid($entry['version'] ?? null)) continue;
        $available[$entry['client']][$entry['version']] = true;
    }
    return $available;
}

/** Replace only an unavailable store selection; custom builds remain untouched. */
function pmssTorrentClientsPostSetupFallback(string $user, string $home, string $client, array $available, string $default, array $ops): bool
{
    $root = rtrim($ops['root'] ?? '/home/.pmss/clients', '/');
    $link = $home.'/bin/'.$client.'/default';
    $prefix = $root.'/'.$client.'/';
    $noticeDir = $home.'/.config/pmss/torrentClients';
    $notice = $noticeDir.'/'.$client.'.notice';
    $binaries = pmssTorrentClientsBinaryNames();
    if (!isset($binaries[$client])) return false;
    $command = 'selected=$(readlink '.escapeshellarg($link).' 2>/dev/null) || exit 1; '
        .'case "$selected" in '.escapeshellarg($prefix).'*) ;; *) exit 1;; esac; ';
    if ($client === 'qbittorrent') $command = 'mkdir -m 700 -p '.escapeshellarg($noticeDir).' || exit 1; '
        .'exec 9>>'.escapeshellarg($noticeDir.'/qbittorrent.lock').'; flock -w 120 9 || exit 1; '.$command;
    foreach (array_keys($available[$client] ?? []) as $version) {
        if ($version === 'system') continue;
        $path = $prefix.$version;
        // Store Deluge versions are virtualenvs with the daemon under bin/ (ADR 0110); the others are flat.
        $binary = pmssTorrentClientsStoreBinaryPath($root, $client, $version, $client === 'deluge' ? 'venv' : 'static');
        $command .= 'if [ "$selected" = '.escapeshellarg($path)
            .' ] && [ -d "$selected" ] && [ ! -L "$selected" ]'
            .' && [ -f '.escapeshellarg($binary).' ]'
            .' && [ ! -L '.escapeshellarg($binary).' ]; then exit 1; fi; ';
    }
    // Best-effort stopped restore must never prevent repair of a dangling selection.
    if ($client === 'qbittorrent') $command .= 'if [ -f '.escapeshellarg($home.'/www/qbittorrentStart.php')
        .' ]; then cd '.escapeshellarg($home)
        .' && php '.escapeshellarg($home.'/www/torrentClientsResolve.php').' qbittorrent --restore '
        .escapeshellarg($home).'; fi; ';
    if ($default === 'system') {
        $command .= 'rm -f -- '.escapeshellarg($link).' || exit 1; ';
    } else {
        $target = $prefix.$default;
        $command .= 'temp='.escapeshellarg($link.'.pmss-').'$$; [ ! -e "$temp" ] && [ ! -L "$temp" ] || exit 1; '
            .'ln -s '.escapeshellarg($target).' "$temp" && mv -Tf -- "$temp" '.escapeshellarg($link)
            .' || { rm -f -- "$temp"; exit 1; }; ';
    }
    $message = pmssTorrentClientsPostSetupFallbackMessage($client);
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
        if ($entry['version'] !== 'system' && !isset($available[$client][$entry['version']])) continue;
        if (pmssTorrentClientsPostSetupFallback($user, $home, $client, $available, $entry['version'], $ops)) {
            $log($user, '[WARN] '.pmssTorrentClientsPostSetupFallbackMessage($client));
        }
    }
}
