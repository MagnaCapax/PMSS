<?php
/**
 * XML-RPC codec helpers for rTorrent SCGI calls.
 */

/**
 * Build an SCGI request payload from XML-RPC data.
 */
function rtorrentScgiFormatRequest(string $xmlData): string
{
    $header = "CONTENT_LENGTH\x00" . strlen($xmlData) . "\x00SCGI\x001\x00";
    return strlen($header) . ':' . $header . ',' . $xmlData;
}

/**
 * Wrap one XML-RPC typed value node.
 */
function rtorrentScgiFormatXmlrpcTypedValue(string $type, string $body): string
{
    return '<value><' . $type . '>' . $body . '</' . $type . '></value>';
}

/**
 * Format one XML-RPC value node for the scalar/list subset PMSS uses.
 *
 * @param mixed $value
 */
function rtorrentScgiFormatXmlrpcValue($value): string
{
    if (is_array($value)) {
        $items = implode('', array_map('rtorrentScgiFormatXmlrpcValue', $value));
        return rtorrentScgiFormatXmlrpcTypedValue('array', '<data>' . $items . '</data>');
    }
    if (is_int($value)) {
        return rtorrentScgiFormatXmlrpcTypedValue('int', (string) $value);
    }
    if (is_bool($value)) {
        return rtorrentScgiFormatXmlrpcTypedValue('boolean', $value ? '1' : '0');
    }
    if (is_float($value)) {
        return rtorrentScgiFormatXmlrpcTypedValue('double', (string) $value);
    }

    return rtorrentScgiFormatXmlrpcTypedValue('string', htmlspecialchars((string) $value, ENT_XML1, 'UTF-8'));
}

/**
 * Build an XML-RPC method call with ordered parameters.
 *
 * @param array<int, mixed> $params
 */
function rtorrentScgiFormatXmlrpcParamsCall(string $method, array $params = []): string
{
    $paramsXml = implode('', array_map(static function ($param): string {
        return '<param>' . rtorrentScgiFormatXmlrpcValue($param) . '</param>';
    }, $params));

    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<methodCall>'
        . '<methodName>' . htmlspecialchars($method, ENT_XML1, 'UTF-8') . '</methodName>'
        . '<params>' . $paramsXml . '</params></methodCall>';
}

/**
 * Decode one XML-RPC value node into a PHP scalar or nested list.
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
    switch ($child->getName()) {
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
            return array_map('rtorrentScgiDecodeXmlrpcValue', iterator_to_array($child->data->value, false));
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
 * Decode a raw SCGI XML-RPC response into its first value payload.
 *
 * @return mixed False on decode failure or XML-RPC fault, otherwise first value.
 */
function rtorrentScgiDecodeResponse(string $response)
{
    $offset = strpos($response, '<?xml');
    if ($offset === false || !function_exists('simplexml_load_string')) {
        return false;
    }

    $xml = @simplexml_load_string(substr($response, $offset));
    if (!($xml instanceof \SimpleXMLElement) || isset($xml->fault->value) || !isset($xml->params->param->value)) {
        return false;
    }

    return rtorrentScgiDecodeXmlrpcValue($xml->params->param->value);
}
