<?php
/** Read-only checks for known custom rc incompatibilities before a version switch. */

/** Identify bare removed log groups without interpreting or changing customer commands. */
function pmssConfigureTorrentClientRtorrentRcCheck($home, $version)
{
    // Limit the check to releases whose removed groups are known (ADR 0141).
    if (!in_array($version, array('0.16.25', '0.16.26'), true)) return null;
    $raw = pmssConfigureFileRead($home.'/.rtorrent.rc.custom', 262144);
    foreach (preg_split('/\r\n|\n|\r/', $raw) as $index => $line) {
        // Anchoring excludes comments, catch wrappers and deferred command strings.
        if (preg_match('/\A[ \t]*log\.add_output[ \t]*=[ \t]*(["\']?)(socket_critical|storage_critical)\1[ \t]*,/',
            $line, $match) !== 1) continue;
        if ($match[2] === 'storage_critical' && $version === '0.16.25') continue;
        return 'Cannot switch to rTorrent '.$version.': .rtorrent.rc.custom line '.($index + 1)
            .' uses the unavailable log group '.$match[2].'. Wrap this log.add_output command in catch'
            .' before switching. Nothing was changed.';
    }
    return null;
}
