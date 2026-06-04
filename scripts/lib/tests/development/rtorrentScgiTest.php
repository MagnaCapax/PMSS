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

        $this->assertSame(
            '<?xml version="1.0" encoding="UTF-8"?><methodCall><methodName>d.multicall2</methodName><params>'
            .'<param><value><string>main</string></value></param>'
            .'<param><value><string>d.get_hash=</string></value></param>'
            .'<param><value><array><data><value><string>nested</string></value><value><int>1</int></value></data></array></value></param>'
            .'</params></methodCall>',
            $xml
        );
    }

    public function testDecodeResponseParsesXmlrpcValues(): void
    {
        foreach ([
            'scalar i8' => ['<i8>42</i8>', 42],
            'array' => ['<array><data><value><string>a</string></value><value><string>b</string></value></data></array>', ['a', 'b']],
            'boolean' => ['<boolean>1</boolean>', true],
            'double' => ['<double>4.25</double>', 4.25],
            'struct' => ['<struct><member><name>enabled</name><value><boolean>0</boolean></value></member><member><name>rate</name><value><int>9</int></value></member></struct>', ['enabled' => false, 'rate' => 9]],
        ] as $label => $case) {
            $response = $this->xmlrpcResponseWrap('<?xml version="1.0"?><methodResponse><params><param><value>'.$case[0].'</value></param></params></methodResponse>');
            $this->assertSame($case[1], rtorrentScgiDecodeResponse($response), $label);
        }
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

    public function testSocketQueueSnapshotParsesOnlyMatchingListenSockets(): void
    {
        foreach ([
            'matching listen socket' => [
                [
                    'Netid State  Recv-Q Send-Q Local Address:Port Peer Address:Port',
                    'u_str LISTEN 101    100    /home/alice/.rtorrent.socket 12345 * 0',
                ],
                ['recvQ' => 101, 'sendQ' => 100],
            ],
            'other socket' => [
                ['u_str LISTEN 101 100 /home/bob/.rtorrent.socket 12345 * 0'],
                null,
            ],
            'malformed columns' => [
                [
                    'u_str LISTEN nope 100 /home/alice/.rtorrent.socket 12345 * 0',
                    'u_str ESTAB 101 100 /home/alice/.rtorrent.socket 12345 * 0',
                ],
                null,
            ],
        ] as $label => $case) {
            $this->assertSame($case[1], rtorrentScgiSocketQueueSnapshotFromLines($case[0], '/home/alice/.rtorrent.socket'), $label);
        }
    }

    public function testSocketQueueSaturatedRequiresRecvQAtBacklog(): void
    {
        $this->assertTrue(rtorrentScgiSocketQueueSaturated(['recvQ' => 100, 'sendQ' => 100]));
        $this->assertFalse(rtorrentScgiSocketQueueSaturated(['recvQ' => 99, 'sendQ' => 100]));
        $this->assertFalse(rtorrentScgiSocketQueueSaturated(['recvQ' => 1, 'sendQ' => 0]));
    }

    public function testCallAndSendRejectInvalidBoundaries(): void
    {
        $missingSocket = $this->fakeSocketPath();
        foreach ([
            'call should reject missing socket' => function () use ($missingSocket) { return rtorrentScgiCall($missingSocket, 'system.api_version', [], 1); },
            'call should reject empty path' => function () { return rtorrentScgiCall('', 'system.api_version', [], 1); },
            'send should reject missing socket' => function () use ($missingSocket) { return rtorrentScgiSend($missingSocket, 'test', 1); },
            'send should reject empty request' => function () use ($missingSocket) { return rtorrentScgiSend($missingSocket, '', 1); },
            'send should reject invalid timeout' => function () use ($missingSocket) { return rtorrentScgiSend($missingSocket, 'test', 0); },
        ] as $message => $callback) {
            $this->assertSame(false, $callback(), $message);
        }
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
