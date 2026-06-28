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

    $remove = [];
    $replace = [];
    $inline = [];
    foreach (pmssRtorrentLegacyDirectiveCatalog() as $legacy => $rule) {
        if ($rule['replacement'] === null) {
            $remove[] = $legacy;
            continue;
        }
        $replace[$legacy] = $rule['replacement'];
        if (isset($rule['inline'])) {
            $inline['/(?<![A-Za-z0-9_.])'.preg_quote($legacy, '/').'(?==)/'] = $rule['inline'];
        }
    }

    $normalizeInlineAliases = static function (string $line) use ($inline): string {
        return $inline === [] ? $line : (string) preg_replace(array_keys($inline), array_values($inline), $line);
    };

    $normalizedLines = [];
    $seenManagedLines = [];
    foreach ($lines as $line) {
        foreach ($remove as $legacy) {
            if (preg_match('/^\s*'.preg_quote($legacy, '/').'\s*=\s*.+?\s*$/', $line) === 1) {
                continue 2;
            }
        }

        foreach ($replace as $legacy => $modern) {
            $matches = [];
            if (preg_match('/^\s*'.preg_quote($legacy, '/').'\s*=\s*(.+?)\s*$/', $line, $matches) === 1) {
                $line = $normalizeInlineAliases($modern.' = '.$matches[1]);
                $trimmed = trim($line);
                if (!isset($seenManagedLines[$trimmed])) {
                    $normalizedLines[] = $line;
                    $seenManagedLines[$trimmed] = true;
                }
                continue 2;
            }
        }

        $line = $normalizeInlineAliases($line);
        $trimmed = trim($line);
        foreach ($replace as $modern) {
            if (strpos($trimmed, $modern.' = ') !== 0) {
                continue;
            }
            if (isset($seenManagedLines[$trimmed])) {
                continue 2;
            }
            $seenManagedLines[$trimmed] = true;
            break;
        }
        $normalizedLines[] = $line;
    }

    $normalized = implode($lineSeparator, $normalizedLines);
    return ($template !== '' && preg_match('/(?:\r\n|\r|\n)\z/', $template) === 1) ? $normalized.$lineSeparator : $normalized;
}
