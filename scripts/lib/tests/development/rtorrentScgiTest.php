<?php
/**
 * Tests for rTorrent SCGI communication helpers.
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 */

namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/rtorrent/scgi.php';

class RtorrentScgiTest extends TestCase
{
    private function fakeSocketPath(): string
    {
        return '/tmp/pmss-test-nonexistent-' . getmypid() . '.socket';
    }

    private function xmlrpcResponseWrap(string $body): string
    {
        return "Status: 200 OK\r\n\r\n" . $body;
    }

    public function testScgiFormatRequestProducesValidHeader(): void
    {
        $xmlData = '<test>data</test>';
        $request = rtorrentScgiFormatRequest($xmlData);

        $this->assertStringContainsString('CONTENT_LENGTH', $request);
        $this->assertStringContainsString('SCGI', $request);
        $this->assertStringContainsString($xmlData, $request);

        $this->assertTrue(
            preg_match('/^(\d+):/', $request, $m) === 1,
            'Request should start with netstring length prefix'
        );
    }

    public function testScgiFormatRequestContentLength(): void
    {
        $xmlData = 'short';
        $request = rtorrentScgiFormatRequest($xmlData);

        $this->assertStringContainsString(
            "CONTENT_LENGTH\x00" . strlen($xmlData) . "\x00",
            $request
        );
    }

    public function testXmlrpcCallFormattingProducesValidXml(): void
    {
        $method = 'system.api_version';
        $xml = rtorrentScgiFormatXmlrpcParamsCall($method);

        $this->assertStringContainsString('<?xml version="1.0"', $xml);
        $this->assertStringContainsString('<methodCall>', $xml);
        $this->assertStringContainsString('<methodName>system.api_version</methodName>', $xml);
        $this->assertStringContainsString('</methodCall>', $xml);
    }

    public function testXmlrpcIntCallFormattingProducesValidXml(): void
    {
        $xml = rtorrentScgiFormatXmlrpcParamsCall('throttle.global_up.max_rate.set', [42]);

        $this->assertStringContainsString('<methodName>throttle.global_up.max_rate.set</methodName>', $xml);
        $this->assertStringContainsString('<int>42</int>', $xml);
        $this->assertStringContainsString('<params>', $xml);
    }

    public function testXmlrpcParamsCallFormatsStringAndListValues(): void
    {
        $xml = rtorrentScgiFormatXmlrpcParamsCall('d.multicall2', ['main', 'd.get_hash=', ['nested', 1]]);

        $this->assertStringContainsString('<methodName>d.multicall2</methodName>', $xml);
        $this->assertStringContainsString('<string>main</string>', $xml);
        $this->assertStringContainsString('<string>d.get_hash=</string>', $xml);
        $this->assertStringContainsString('<array><data>', $xml);
        $this->assertStringContainsString('<int>1</int>', $xml);
    }

    public function testDecodeResponseParsesScalarAndArrayValues(): void
    {
        $scalar = $this->xmlrpcResponseWrap('<?xml version="1.0"?><methodResponse><params><param><value><i8>42</i8></value></param></params></methodResponse>');
        $decodedScalar = rtorrentScgiDecodeResponse($scalar);
        $this->assertEquals(42, $decodedScalar);

        $array = $this->xmlrpcResponseWrap('<?xml version="1.0"?><methodResponse><params><param><value><array><data><value><string>a</string></value><value><string>b</string></value></data></array></value></param></params></methodResponse>');
        $decodedArray = rtorrentScgiDecodeResponse($array);
        $this->assertEquals(['a', 'b'], $decodedArray);
    }

    public function testDecodeResponseParsesBooleanDoubleAndStructValues(): void
    {
        $boolean = $this->xmlrpcResponseWrap('<?xml version="1.0"?><methodResponse><params><param><value><boolean>1</boolean></value></param></params></methodResponse>');
        $this->assertTrue(rtorrentScgiDecodeResponse($boolean));

        $double = $this->xmlrpcResponseWrap('<?xml version="1.0"?><methodResponse><params><param><value><double>4.25</double></value></param></params></methodResponse>');
        $this->assertSame(4.25, rtorrentScgiDecodeResponse($double));

        $struct = $this->xmlrpcResponseWrap('<?xml version="1.0"?><methodResponse><params><param><value><struct><member><name>enabled</name><value><boolean>0</boolean></value></member><member><name>rate</name><value><int>9</int></value></member></struct></value></param></params></methodResponse>');
        $this->assertSame(['enabled' => false, 'rate' => 9], rtorrentScgiDecodeResponse($struct));
    }

    public function testXmlrpcCallEscapesSpecialChars(): void
    {
        $method = 'test<>&method';
        $xml = rtorrentScgiFormatXmlrpcParamsCall($method);

        $this->assertStringContainsString('&lt;', $xml);
        $this->assertStringContainsString('&gt;', $xml);
        $this->assertStringContainsString('&amp;', $xml);
    }

    public function testSocketPathGeneration(): void
    {
        $path = rtorrentScgiSocketPath('alice');
        $this->assertEquals('/home/alice/.rtorrent.socket', $path);

        $path = rtorrentScgiSocketPath('bob123');
        $this->assertEquals('/home/bob123/.rtorrent.socket', $path);
    }

    public function testPingReturnsFalseForMissingSocket(): void
    {
        $result = rtorrentScgiPing($this->fakeSocketPath(), 1);

        $this->assertTrue($result === false, 'Ping should return false for missing socket');
    }

    public function testPingWithEmptyPathReturnsFalse(): void
    {
        $result = rtorrentScgiPing('', 1);
        $this->assertTrue($result === false, 'Ping should return false for empty path');
    }

    public function testScgiSendReturnsFalseForMissingSocket(): void
    {
        $result = rtorrentScgiSend($this->fakeSocketPath(), 'test', 1);

        $this->assertTrue($result === false, 'Send should return false for missing socket');
    }

    public function testScgiSendReturnsFalseForEmptyRequest(): void
    {
        $result = rtorrentScgiSend($this->fakeSocketPath(), '', 1);

        $this->assertTrue($result === false, 'Send should return false for empty requests');
    }

    public function testScgiSendReturnsFalseForInvalidTimeout(): void
    {
        $result = rtorrentScgiSend($this->fakeSocketPath(), 'test', 0);

        $this->assertTrue($result === false, 'Send should return false for invalid timeouts');
    }

    public function testScgiSendReturnsFalseForRegularFilePath(): void
    {
        $path = sys_get_temp_dir() . '/pmss-rtorrent-scgi-file-' . getmypid();
        file_put_contents($path, "not-a-socket\n");

        try {
            $result = rtorrentScgiSend($path, 'test', 1);
            $this->assertTrue($result === false, 'Send should return false for regular file paths');
        } finally {
            @unlink($path);
        }
    }

    public function testCompleteRequestFormatting(): void
    {
        $method = 'system.hostname';
        $xmlrpc = rtorrentScgiFormatXmlrpcParamsCall($method);
        $request = rtorrentScgiFormatRequest($xmlrpc);

        $this->assertStringContainsString('CONTENT_LENGTH', $request);
        $this->assertStringContainsString('system.hostname', $request);
        $this->assertStringContainsString('methodCall', $request);

        $this->assertTrue(strlen($request) > 50, 'Complete request should be substantial');
    }

    public function testRequestHeaderUsesNullByteSeparators(): void
    {
        $request = rtorrentScgiFormatRequest('data');

        $nullCount = substr_count($request, "\x00");
        $this->assertTrue(
            $nullCount >= 4,
            'Header should contain at least 4 null bytes (CONTENT_LENGTH, value, SCGI, 1)'
        );
    }
}
