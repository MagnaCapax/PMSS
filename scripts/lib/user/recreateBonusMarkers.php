<?php
/**
 * Re-render the bonus display copies in a rebuilt home from the root store.
 *
 * A rebuild moves the old home aside, so ~/.bonus and ~/.bonusTraffic are gone from the new
 * one. Both are display copies (ADR 0213): the value is root's own record in
 * /etc/seedbox/config/users/<user>.json, which a rebuild leaves in place. The backup copy is
 * account-writable and is never read here, so a value the account edited before the rebuild
 * cannot reach root state or the new display file.
 *
 * @license GPL-3.0-only
 */
require_once __DIR__.'/homeMarkerRegistry.php';
require_once __DIR__.'/userConfigStore.php';
require_once dirname(__DIR__).'/lighttpd/userFileWrite.php';

if (!function_exists('pmssRecreateBonusMarkersRender')) {
    /**
     * Write one display copy per stored bonus value that is above zero.
     *
     * Registry-driven: any later marker whose store key is one of the UserConfigStore value keys
     * is covered without a change here. A failed write is reported, never fatal: the value is
     * display only and the daily bonus re-assert repairs it.
     *
     * @param callable|null $write same signature as pmssWriteManagedFile (path, content, owner, group, mode)
     * @return array<string,bool> marker filename => written, for markers that had a stored value
     */
    function pmssRecreateBonusMarkersRender(string $user, string $home, ?UserConfigStore $store = null, ?callable $write = null): array
    {
        $store = $store ?? new UserConfigStore();
        $write = $write ?? 'pmssWriteManagedFile';
        $result = [];
        foreach (pmssHomeMarkerRegistry() as $marker => $entry) {
            if ($entry['store'] === null || !in_array($entry['store'], UserConfigStore::VALUE_KEYS, true)) {
                continue;
            }
            // getInt() yields 0 for a missing, non-integer, negative or over-max value: no marker.
            $value = $store->getInt($user, $entry['store'], $entry['max'] ?? PHP_INT_MAX);
            if ($value > 0) {
                $result[$marker] = (bool) $write(rtrim($home, '/').'/'.$marker, (string) $value, 'root', $user, (int) $entry['mode']);
            }
        }
        return $result;
    }
}
