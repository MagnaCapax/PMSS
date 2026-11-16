<?php
/** Pure rTorrent configuration parsing and exact managed-line repairs. @license GPL-3.0-only */

require_once dirname(__DIR__).'/runtime/config.php';

// Builds without ipv4_filter.load must still start; customer overrides stay outside these repairs.
const PMSS_RTORRENT_LOCALNET = 'catch = {"ipv4_filter.load=/etc/seedbox/config/localnet,preferred"}';

/** Parse the legacy single-equals format; duplicate keys retain their first position and last value. */
function pmssRtorrentConfigParse(string $text): array
{
    $config = [];
    foreach (pmssConfigAssignments(pmssConfigTextLines($text, ['#', '/'])) as $name => $value) {
        if (strpos($value, '=') === false) $config[trim($name)] = trim($value);
    }
    return $config;
}

/** Replace only the trailing LF-framed legacy localnet line, retaining every trailing LF byte. */
function pmssRtorrentConfigLocalnetReplace(string $text): string
{
    $legacy = "\nipv4_filter.load = /etc/seedbox/config/localnet, preferred";
    $body = rtrim($text, "\n");
    return $body !== '' && substr($body, -strlen($legacy)) === $legacy
        ? substr($body, 0, -strlen($legacy))."\n".PMSS_RTORRENT_LOCALNET.substr($text, strlen($body)) : $text;
}

/** ADR 0141: repair complete encoding lines without changing comments, CRLF or edited directives. */
function pmssRtorrentConfigEncodingReplace(string $text): string
{
    return preg_replace('/^encoding\.add = utf8(?=\r?$)/m',
        'branch = ((equal, ((system.client_version)), ((cat, "0.9.8")))), '
        .'((encoding.add, utf8)), ((network.bind_address.set, "0.0.0.0"))', $text);
}

/** ADR 0114: replace only the former managed branch; retain customer directives and newline bytes. */
function pmssRtorrentConfigIpv4Replace(string $text): string
{
    $legacy = pmssRtorrentConfigEncodingReplace('encoding.add = utf8');
    return preg_replace_callback('/^'.preg_quote($legacy, '/').'(\r?)(?=$)/m',
        static function (array $match): string {
            $newline = $match[1]."\n";
            return 'branch = ((equal, ((system.client_version)), ((cat, "0.9.8")))), '
                .'((encoding.add, utf8)), ((cat))'.$newline
                .'network.bind_address.set = 0.0.0.0'.$newline
                .'catch = {"network.block.ipv6.set=yes"}'.$match[1];
        }, $text);
}

/** Catch only the known unsupported bare command; all other custom-file bytes survive. */
function pmssRtorrentConfigIpv6CatchReplace(string $text): string
{
    return preg_replace('/^network\.block\.ipv6\.set = yes(?=\r?$)/m',
        'catch = {"network.block.ipv6.set=yes"}', $text);
}
