<?php
/** Converge the host's torrent-client store during each normal update. */

/** Never let optional client setup abort update-step2's uncaught include. */
function pmssTorrentClientStoreRun(array $ops = []): void {
    try {
        require_once dirname(__DIR__, 2).'/torrentClients/catalog.php';
        require_once dirname(__DIR__, 2).'/torrentClients/store.php';
        require_once dirname(__DIR__, 2).'/torrentClients/projection.php';
        $load = $ops['load'] ?? 'pmssTorrentClientsCatalogLoad';
        $store = $ops['store'] ?? 'pmssTorrentClientsStoreConverge';
        $project = $ops['project'] ?? 'pmssTorrentClientsProjectionWrite';
        $catalog = $load($ops['catalog'] ?? '/etc/seedbox/config/torrentClients.json');
        if ($catalog === []) {
            error_log('[WARN] torrent client catalog unavailable; skipping optional store');
            return;
        }
        $store($catalog);
        if (!$project($catalog)) error_log('[WARN] torrent client projection write failed');
    } catch (Throwable $error) {
        error_log('[WARN] torrent client store skipped: '.$error->getMessage());
    }
}

// The development runner can load and exercise the hook without host effects.
if (!defined('PMSS_TEST_MODE') || !PMSS_TEST_MODE) pmssTorrentClientStoreRun();
