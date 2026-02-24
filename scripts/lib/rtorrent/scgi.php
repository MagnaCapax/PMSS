<?php
/**
 * rTorrent SCGI communication helpers.
 *
 * Provides low-level SCGI protocol support for communicating with rTorrent
 * instances via Unix domain sockets. Designed for health checks and simple
 * xmlrpc calls from watchdog/cron scripts.
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 * @license   Proprietary
 */

/**
 * Build an SCGI request payload from xmlrpc data.
 *
 * The SCGI protocol wraps content with a netstring-style header containing
 * key-value pairs. rTorrent requires at minimum CONTENT_LENGTH and SCGI=1.
 *
 * @param string $xmlData The xmlrpc request body.
 *
 * @return string The complete SCGI request ready to send over the socket.
 */
function rtorrentScgiFormatRequest(string $xmlData): string
{
    $header = "CONTENT_LENGTH\x00" . strlen($xmlData) . "\x00SCGI\x001\x00";
    return strlen($header) . ':' . $header . ',' . $xmlData;
}

/**
 * Build a minimal xmlrpc method call with no parameters.
 *
 * Used for health checks where we just need to verify the instance responds.
 *
 * @param string $method The xmlrpc method name (e.g., 'system.api_version').
 *
 * @return string The xmlrpc request XML.
 */
function rtorrentScgiFormatXmlrpcCall(string $method): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<methodCall>'
        . '<methodName>' . htmlspecialchars($method, ENT_XML1, 'UTF-8') . '</methodName>'
        . '<params></params>'
        . '</methodCall>';
}

/**
 * Build an xmlrpc call with a single integer parameter.
 *
 * Used for lightweight settings updates (e.g. throttle adjustments).
 *
 * @param string $method The xmlrpc method name.
 * @param int    $value  Integer parameter to send.
 *
 * @return string The xmlrpc request XML.
 */
function rtorrentScgiFormatXmlrpcIntCall(string $method, int $value): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<methodCall>'
        . '<methodName>' . htmlspecialchars($method, ENT_XML1, 'UTF-8') . '</methodName>'
        . '<params>'
        . '<param><value><int>' . $value . '</int></value></param>'
        . '</params>'
        . '</methodCall>';
}

/**
 * Send an SCGI request to a Unix socket and return the response.
 *
 * Handles connection timeout and read timeout separately. Returns false on
 * any error (connection refused, timeout, malformed response).
 *
 * @param string $socketPath Absolute path to the Unix socket.
 * @param string $request    The SCGI-formatted request to send.
 * @param int    $timeout    Timeout in seconds for both connect and read.
 *
 * @return string|false The raw response on success, false on any failure.
 */
function rtorrentScgiSend(string $socketPath, string $request, int $timeout = 5)
{
    // Validate socket path exists before attempting connection.
    if (!file_exists($socketPath)) {
        return false;
    }

    $socket = @fsockopen('unix://' . $socketPath, 0, $errno, $errstr, $timeout);
    if ($socket === false) {
        return false;
    }

    // Set read/write timeout on the stream.
    stream_set_timeout($socket, $timeout);

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

        // Check for timeout during read.
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
 * Send an xmlrpc int call and return whether rTorrent accepted it.
 *
 * @param string $socketPath Absolute path to the rTorrent Unix socket.
 * @param string $method     xmlrpc method name.
 * @param int    $value      Integer parameter.
 * @param int    $timeout    Timeout in seconds.
 *
 * @return bool True when a valid response is received.
 */
function rtorrentScgiCallInt(string $socketPath, string $method, int $value, int $timeout = 5): bool
{
    $xmlrpc = rtorrentScgiFormatXmlrpcIntCall($method, $value);
    $request = rtorrentScgiFormatRequest($xmlrpc);

    $response = rtorrentScgiSend($socketPath, $request, $timeout);
    if ($response === false) {
        return false;
    }
    if (strpos($response, '<fault>') !== false) {
        return false;
    }
    return strpos($response, '<value>') !== false;
}

/**
 * Check if an rTorrent instance is responsive via its SCGI socket.
 *
 * Sends a lightweight xmlrpc call (system.api_version) and verifies we get
 * a valid response containing a <value> element. This confirms the instance
 * is not only running but also processing xmlrpc requests.
 *
 * @param string $socketPath Absolute path to the rTorrent Unix socket.
 * @param int    $timeout    Timeout in seconds (default 5).
 *
 * @return bool True if responsive, false if unresponsive or unreachable.
 */
function rtorrentScgiPing(string $socketPath, int $timeout = 5): bool
{
    $xmlrpc = rtorrentScgiFormatXmlrpcCall('system.api_version');
    $request = rtorrentScgiFormatRequest($xmlrpc);

    $response = rtorrentScgiSend($socketPath, $request, $timeout);
    if ($response === false) {
        return false;
    }

    // A valid xmlrpc response contains <value> tags.
    // We don't parse the full response; just verify it looks valid.
    return strpos($response, '<value>') !== false;
}

/**
 * Get the standard socket path for a user's rTorrent instance.
 *
 * @param string $username The system username.
 *
 * @return string The expected socket path.
 */
function rtorrentScgiSocketPath(string $username): string
{
    return '/home/' . $username . '/.rtorrent.socket';
}
