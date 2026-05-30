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
    $offset = strpos($response, '<?xml');
    if ($offset === false || !function_exists('simplexml_load_string')) {
        return false;
    }

    $xml = @simplexml_load_string(substr($response, $offset));
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
 * Parse `ss -xln` output and return the queue depth for one Unix socket.
 *
 * The watchdog treats `ss` output as a trust boundary: match the exact socket
 * path and accept only numeric Recv-Q/Send-Q columns from LISTEN rows.
 *
 * @param string[] $lines      Lines from `ss -xln`.
 * @param string   $socketPath Absolute Unix socket path to match.
 *
 * @return array{recvQ:int,sendQ:int}|null Queue snapshot when found.
 */
function rtorrentScgiSocketQueueSnapshotFromLines(array $lines, string $socketPath): ?array
{
    if ($socketPath === '') {
        return null;
    }

    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '' || strpos($line, $socketPath) === false) {
            continue;
        }

        $columns = preg_split('/\s+/', $line);
        if (!is_array($columns) || count($columns) < 5) {
            continue;
        }
        if (($columns[1] ?? '') !== 'LISTEN') {
            continue;
        }
        if (!in_array($socketPath, $columns, true)) {
            continue;
        }
        if (!preg_match('/^\d+$/', $columns[2] ?? '') || !preg_match('/^\d+$/', $columns[3] ?? '')) {
            continue;
        }

        return [
            'recvQ' => (int) $columns[2],
            'sendQ' => (int) $columns[3],
        ];
    }

    return null;
}

/**
 * Read the rTorrent SCGI socket accept queue from `ss -xln`.
 *
 * @param string $socketPath Absolute Unix socket path to inspect.
 *
 * @return array{recvQ:int,sendQ:int}|null Queue snapshot when available.
 */
function rtorrentScgiSocketQueueSnapshot(string $socketPath): ?array
{
    if ($socketPath === '') {
        return null;
    }

    $out = [];
    $rc = 1;
    @exec('ss -xln 2>/dev/null', $out, $rc);
    if ($rc !== 0) {
        return null;
    }

    return rtorrentScgiSocketQueueSnapshotFromLines($out, $socketPath);
}

/**
 * Decide whether the SCGI listen queue is saturated enough to signal a wedge.
 *
 * @param array{recvQ:int,sendQ:int} $snapshot Queue snapshot.
 *
 * @return bool True when Recv-Q has reached the listen backlog.
 */
function rtorrentScgiSocketQueueSaturated(array $snapshot): bool
{
    $recvQ = (int) ($snapshot['recvQ'] ?? 0);
    $sendQ = (int) ($snapshot['sendQ'] ?? 0);

    return $sendQ > 0 && $recvQ >= $sendQ;
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
