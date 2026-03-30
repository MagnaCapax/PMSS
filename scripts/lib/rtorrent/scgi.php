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
 * Format one xmlrpc value node for a small subset of scalar and list params.
 *
 * The PMSS rTorrent callers only need integers, strings, booleans, and lists
 * of those same values for lightweight stats and maintenance calls.
 *
 * @param mixed $value Parameter value to encode.
 *
 * @return string Xmlrpc value fragment.
 */
function rtorrentScgiFormatXmlrpcValue($value): string
{
    if (is_array($value)) {
        $items = '';
        foreach ($value as $item) {
            $items .= rtorrentScgiFormatXmlrpcValue($item);
        }

        return '<value><array><data>' . $items . '</data></array></value>';
    }

    if (is_int($value)) {
        $type = 'int';
        $body = (string) $value;
    } elseif (is_bool($value)) {
        $type = 'boolean';
        $body = $value ? '1' : '0';
    } elseif (is_float($value)) {
        $type = 'double';
        $body = (string) $value;
    } else {
        return '<value><string>' . htmlspecialchars((string) $value, ENT_XML1, 'UTF-8') . '</string></value>';
    }

    return '<value><' . $type . '>' . $body . '</' . $type . '></value>';
}

/**
 * Build an xmlrpc call with arbitrary parameters.
 *
 * @param string     $method xmlrpc method name.
 * @param array<int, mixed> $params Ordered xmlrpc parameters.
 *
 * @return string The xmlrpc request XML.
 */
function rtorrentScgiFormatXmlrpcParamsCall(string $method, array $params = []): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<methodCall>'
        . '<methodName>' . htmlspecialchars($method, ENT_XML1, 'UTF-8') . '</methodName>'
        . '<params>';

    foreach ($params as $param) {
        $xml .= '<param>' . rtorrentScgiFormatXmlrpcValue($param) . '</param>';
    }

    return $xml . '</params></methodCall>';
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
    // Reject invalid boundaries before touching the socket path.
    if ($socketPath === '' || $request === '' || $timeout < 1) {
        return false;
    }

    // Validate the target exists and still resolves to a Unix socket.
    if (!file_exists($socketPath) || @filetype($socketPath) !== 'socket') {
        return false;
    }

    $socket = @fsockopen('unix://' . $socketPath, 0, $errno, $errstr, $timeout);
    if ($socket === false) {
        return false;
    }

    // Set read/write timeout on the stream.
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
    return rtorrentScgiCall($socketPath, $method, [$value], $timeout) !== false;
}

/**
 * Extract the xmlrpc body from an SCGI response.
 *
 * @param string $response Raw SCGI response including headers.
 *
 * @return string|false Xml body when present, otherwise false.
 */
function rtorrentScgiExtractXmlBody(string $response)
{
    $offset = strpos($response, '<?xml');
    if ($offset === false) {
        return false;
    }

    return substr($response, $offset);
}

/**
 * Decode one xmlrpc value node into a PHP scalar or nested list.
 *
 * @param \SimpleXMLElement $valueNode Xmlrpc value node.
 *
 * @return mixed
 */
function rtorrentScgiDecodeXmlrpcValue(\SimpleXMLElement $valueNode)
{
    $children = $valueNode->children();
    if (count($children) === 0) {
        return (string) $valueNode;
    }

    /** @var \SimpleXMLElement $child */
    $child = $children[0];
    $name = $child->getName();

    switch ($name) {
        case 'int':
        case 'i4':
        case 'i8':
            return (int) ((string) $child);

        case 'double':
            return (float) ((string) $child);

        case 'boolean':
            return ((string) $child) === '1';

        case 'string':
            return (string) $child;

        case 'array':
            $values = [];
            foreach ($child->data->value as $item) {
                $values[] = rtorrentScgiDecodeXmlrpcValue($item);
            }

            return $values;

        case 'struct':
            $values = [];
            foreach ($child->member as $member) {
                $values[(string) $member->name] = rtorrentScgiDecodeXmlrpcValue($member->value);
            }

            return $values;
    }

    return (string) $child;
}

/**
 * Decode a raw SCGI xmlrpc response into its first value payload.
 *
 * Returns false for transport-level success paired with xmlrpc faults or
 * malformed response bodies.
 *
 * @param string $response Raw SCGI response.
 *
 * @return mixed False on decode failure or xmlrpc fault, otherwise first value.
 */
function rtorrentScgiDecodeResponse(string $response)
{
    $xmlBody = rtorrentScgiExtractXmlBody($response);
    if ($xmlBody === false || !function_exists('simplexml_load_string')) {
        return false;
    }

    $xml = @simplexml_load_string($xmlBody);
    if (!($xml instanceof \SimpleXMLElement)) {
        return false;
    }

    if (isset($xml->fault->value)) {
        return false;
    }

    if (!isset($xml->params->param->value)) {
        return false;
    }

    return rtorrentScgiDecodeXmlrpcValue($xml->params->param->value);
}

/**
 * Send an xmlrpc call and decode the first response value.
 *
 * @param string            $socketPath Absolute path to the rTorrent Unix socket.
 * @param string            $method     xmlrpc method name.
 * @param array<int, mixed> $params     Ordered parameter list.
 * @param int               $timeout    Timeout in seconds.
 *
 * @return mixed False on transport/decode failure, otherwise decoded value.
 */
function rtorrentScgiCall(string $socketPath, string $method, array $params = [], int $timeout = 5)
{
    $xmlrpc = rtorrentScgiFormatXmlrpcParamsCall($method, $params);
    $request = rtorrentScgiFormatRequest($xmlrpc);

    $response = rtorrentScgiSend($socketPath, $request, $timeout);
    if ($response === false) {
        return false;
    }

    return rtorrentScgiDecodeResponse($response);
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
    return rtorrentScgiCall($socketPath, 'system.api_version', [], $timeout) !== false;
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
