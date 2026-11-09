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
 * Normalize legacy rTorrent template directives to the modern syntax.
 *
 * Existing hosts can still carry historical `template.rtorrent.rc` copies from
 * long-lived installs. Rewriting known legacy keys keeps operator intent intact
 * while removing aliases that newer rTorrent versions reject.
 */
function pmssRtorrentNormalizeLegacyTemplate(string $template): string
{
    $lineSeparator = strpos($template, "\r\n") !== false ? "\r\n" : (strpos($template, "\r") !== false ? "\r" : "\n");
    $lines = preg_split('/\r\n|\r|\n/', $template);
    if (!is_array($lines)) {
        return $template;
    }
    if ($lines !== [] && end($lines) === '') {
        array_pop($lines);
    }

    $catalog = pmssRtorrentLegacyDirectiveCatalog();
    $inline = [];
    foreach ($catalog as $legacy => $rule) {
        if (isset($rule['inline'])) {
            $inline['/(?<![A-Za-z0-9_.])'.preg_quote($legacy, '/').'(?==)/'] = $rule['inline'];
        }
    }

    $normalizedLines = [];
    $seenManagedLines = [];
    foreach ($lines as $line) {
        $managed = false;
        // Dispatch legacy assignments directly; whitespace-only values still count as values.
        if (preg_match('/^\s*([a-z_]+)\s*=\s*(.+?)\s*$/', $line, $matches) === 1 && isset($catalog[$matches[1]])) {
            if ($catalog[$matches[1]]['replacement'] === null) continue;
            $line = $catalog[$matches[1]]['replacement'].' = '.$matches[2];
            $managed = true;
        }
        $line = (string) preg_replace(array_keys($inline), $inline, $line);
        $trimmed = trim($line);
        $assignment = explode(' = ', $trimmed, 2);
        // Rewritten and already-modern directives share one duplicate gate.
        if ($managed || (count($assignment) === 2 && in_array($assignment[0], array_column($catalog, 'replacement'), true))) {
            if (isset($seenManagedLines[$trimmed])) continue;
            $seenManagedLines[$trimmed] = true;
        }
        $normalizedLines[] = $line;
    }

    $normalized = implode($lineSeparator, $normalizedLines);
    return ($template !== '' && preg_match('/(?:\r\n|\r|\n)\z/', $template) === 1) ? $normalized.$lineSeparator : $normalized;
}
