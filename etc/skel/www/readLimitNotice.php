<?php
/**
 * Customer read-limit row and notice (ADR 0232).
 *
 * Reads only ~/.readLimit, which root writes each read-limit cycle: the read rate the account
 * gets right now and whether it read at that rate during the last cycle. A missing, malformed
 * or stale file shows nothing, so hosts without the limit never show a row.
 *
 * @license GPL-3.0-only
 */
if (!function_exists('pmssReadLimitStatusRead')) {
    /** @return array{limit:int,held:bool}|null */
    function pmssReadLimitStatusRead($path = '../.readLimit', $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        if (!is_file($path) || is_link($path) || @filesize($path) > 4096) return null;
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data) || !is_int($data['read_limit'] ?? null) || !is_bool($data['held'] ?? null)
            || !is_int($data['updated'] ?? null) || $data['read_limit'] <= 0) return null;
        // The cycle runs every five minutes; a status three cycles old is no longer the current limit.
        if ($data['updated'] > $now + 60 || $data['updated'] < $now - 900) return null;
        return array('limit' => $data['read_limit'], 'held' => $data['held']);
    }
}

if (!function_exists('pmssReadLimitHtmlBuild')) {
    /** The current limit as a neutral row; a held account also gets the reason its reads wait. */
    function pmssReadLimitHtmlBuild($status)
    {
        if (!is_array($status)) return '';
        $limit = htmlspecialchars(number_format((int) $status['limit']), ENT_QUOTES, 'UTF-8');
        $text = '<p>Disk reads for your account right now: up to '.$limit.' per second. '
            .'This follows how busy the shared disk is and is recalculated every few minutes.</p>';
        if ($status['held']) {
            $text .= '<p>Your account is reading at this rate now, so further reads wait their turn. '
                .'Downloads and file access can feel slower until the disk is less busy or your reading slows.</p>';
        }
        return '<div class="pmss-raid-notice pmss-storage-status-neutral pmss-read-limit">'.$text.'</div>';
    }
}
