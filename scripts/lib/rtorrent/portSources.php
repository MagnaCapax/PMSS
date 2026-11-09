<?php
/** Pure legacy rTorrent reservation schemas and ownership decoding. @license GPL-3.0-only */

require_once dirname(__DIR__).'/runtime/filesystem.php';
require_once dirname(__DIR__).'/runtime/config.php';
require_once dirname(__DIR__).'/runtime/values.php';

/**
 * Reserve only scgi: dht/listen had no template consumer and full pools blocked
 * provisioning (ADR 0081). Their old markers are inert.
 */
function pmssRtorrentPortReservationSpecs(): array
{
    return array(
        'scgi' => array('key' => 'rtorrentPort', 'min' => 4000, 'max' => 24000, 'pattern' => '/^(?:scgi_port|network\.scgi\.open_port)\s*=\s*(?:[^:\s]+:)?([^\s#]+)/i'),
    );
}

/** Build an empty source result, optionally uncertain for every port type. */
function pmssRtorrentPortReservationSourceEmpty(bool $uncertain = false): array
{
    return array(
        'ports' => array(),
        'uncertain' => $uncertain ? array('scgi' => true) : array(),
    );
}

/** Parse reservation fields from one stored user payload. */
function pmssRtorrentPortReservationPayloadSource(array $payload): array
{
    $source = pmssRtorrentPortReservationSourceEmpty();
    $spec = pmssRtorrentPortReservationSpecs()['scgi'];
    if (array_key_exists($spec['key'], $payload)) {
        $port = pmssNetworkPortParseDigits($payload[$spec['key']], $spec['min'], $spec['max']);
        if ($port === null) {
            $source['uncertain']['scgi'] = true;
        } else {
            $source['ports']['scgi'][$port] = true;
        }
    }
    return $source;
}

/** Decode references after guarded file acquisition; retain malformed evidence alongside valid ports. */
function pmssRtorrentPortReservationConfigParse(array $lines): array
{
    $source = pmssRtorrentPortReservationSourceEmpty();
    $spec = pmssRtorrentPortReservationSpecs()['scgi'];
    foreach (pmssConfigLines($lines) as $line) {
        if (preg_match($spec['pattern'], $line, $matches) === 1) {
            $port = pmssNetworkPortParseDigits($matches[1], $spec['min'], $spec['max']);
            $port === null ? $source['uncertain']['scgi'] = true : $source['ports']['scgi'][$port] = true;
        }
    }
    return $source;
}
