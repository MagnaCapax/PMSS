<?php
/**
 * Unix-socket transport for rTorrent SCGI XML-RPC calls.
 */

require_once __DIR__.'/xmlrpc.php';

/**
 * Send an SCGI request to a Unix socket and return the raw response.
 *
 * @return string|false
 */
function rtorrentScgiSend(string $socketPath, string $request, int $timeout = 5)
{
    if ($socketPath === '' || $request === '' || $timeout < 1) {
        return false;
    }
    if (!file_exists($socketPath) || @filetype($socketPath) !== 'socket') {
        return false;
    }

    $socket = @fsockopen('unix://' . $socketPath, 0, $errno, $errstr, $timeout);
    if ($socket === false) {
        return false;
    }
    if (!stream_set_timeout($socket, $timeout)) {
        @fclose($socket);
        return false;
    }

    $written = @fwrite($socket, $request);
    if ($written === false || $written < strlen($request)) {
        @fclose($socket);
        return false;
    }

    $response = '';
    while (!feof($socket)) {
        $chunk = @fread($socket, 4096);
        if ($chunk === false) {
            break;
        }
        $response .= $chunk;

        $meta = stream_get_meta_data($socket);
        if (!empty($meta['timed_out'])) {
            @fclose($socket);
            return false;
        }
    }

    @fclose($socket);
    return $response;
}

/**
 * Send an XML-RPC call and decode the first response value.
 *
 * @param array<int, mixed> $params
 * @return mixed False on transport/decode failure, otherwise decoded value.
 */
function rtorrentScgiCall(string $socketPath, string $method, array $params = [], int $timeout = 5)
{
    $response = rtorrentScgiSend(
        $socketPath,
        rtorrentScgiFormatRequest(rtorrentScgiFormatXmlrpcParamsCall($method, $params)),
        $timeout
    );

    return $response === false ? false : rtorrentScgiDecodeResponse($response);
}
