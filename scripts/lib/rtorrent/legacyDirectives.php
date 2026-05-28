<?php
/**
 * Shared rTorrent legacy directive catalog.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
declare(strict_types=1);

/** Return historical rTorrent directives and their modern PMSS replacements.
 * @return array<string,array{replacement:?string,inline?:string}>
 */
function pmssRtorrentLegacyDirectiveCatalog(): array
{
    return [
        'tracker_numwant' => ['replacement' => 'trackers.numwant.set'],
        'use_udp_trackers' => ['replacement' => 'trackers.use_udp.set'],
        'port_range' => ['replacement' => 'network.port_range.set'],
        'check_hash' => ['replacement' => 'pieces.hash.on_completion.set'],
        'schedule' => ['replacement' => 'schedule2'],
        'schedule_remove' => ['replacement' => 'schedule_remove2'],
        'load_start' => ['replacement' => 'load.start', 'inline' => 'load.start'],
        'load_start_verbose' => ['replacement' => 'load.start_verbose', 'inline' => 'load.start_verbose'],
        'execute' => ['replacement' => 'execute2', 'inline' => 'execute2'],
        'umask' => ['replacement' => null],
        'hash_interval' => ['replacement' => null],
        'hash_max_tries' => ['replacement' => null],
    ];
}

/**
 * Return legacy directive names in diagnostic order.
 *
 * @return string[]
 */
function pmssRtorrentLegacyDirectiveNames(): array
{
    return array_keys(pmssRtorrentLegacyDirectiveCatalog());
}
