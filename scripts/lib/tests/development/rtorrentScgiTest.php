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
    /**
     * Test SCGI request formatting produces valid netstring header.
     */
    public function testScgiFormatRequestProducesValidHeader(): void
    {
        $xmlData = '<test>data</test>';
        $request = rtorrentScgiFormatRequest($xmlData);

        // SCGI header format: length:CONTENT_LENGTH\0<len>\0SCGI\01\0,<data>
        $this->assertStringContainsString('CONTENT_LENGTH', $request);
        $this->assertStringContainsString('SCGI', $request);
        $this->assertStringContainsString($xmlData, $request);

        // Verify the netstring format: <len>:<header>,<body>
        $this->assertTrue(
            preg_match('/^(\d+):/', $request, $m) === 1,
            'Request should start with netstring length prefix'
        );
    }

    /**
     * Test SCGI header contains correct content length.
     */
    public function testScgiFormatRequestContentLength(): void
    {
        $xmlData = 'short';
        $request = rtorrentScgiFormatRequest($xmlData);

        // The CONTENT_LENGTH value should match the xmlData length.
        $this->assertStringContainsString(
            "CONTENT_LENGTH\x00" . strlen($xmlData) . "\x00",
            $request
        );
    }

    /**
     * Test xmlrpc call formatting produces valid XML.
     */
    public function testXmlrpcCallFormattingProducesValidXml(): void
    {
        $method = 'system.api_version';
        $xml = rtorrentScgiFormatXmlrpcCall($method);

        $this->assertStringContainsString('<?xml version="1.0"', $xml);
        $this->assertStringContainsString('<methodCall>', $xml);
        $this->assertStringContainsString('<methodName>system.api_version</methodName>', $xml);
        $this->assertStringContainsString('</methodCall>', $xml);
    }

    /**
     * Test xmlrpc int call formatting produces expected XML.
     */
    public function testXmlrpcIntCallFormattingProducesValidXml(): void
    {
        $xml = rtorrentScgiFormatXmlrpcIntCall('throttle.global_up.max_rate.set', 42);

        $this->assertStringContainsString('<methodName>throttle.global_up.max_rate.set</methodName>', $xml);
        $this->assertStringContainsString('<int>42</int>', $xml);
        $this->assertStringContainsString('<params>', $xml);
    }

    /**
     * Test xmlrpc call escapes special characters in method name.
     */
    public function testXmlrpcCallEscapesSpecialChars(): void
    {
        $method = 'test<>&method';
        $xml = rtorrentScgiFormatXmlrpcCall($method);

        // Special chars should be escaped.
        $this->assertStringContainsString('&lt;', $xml);
        $this->assertStringContainsString('&gt;', $xml);
        $this->assertStringContainsString('&amp;', $xml);
    }

    /**
     * Test socket path generation for users.
     */
    public function testSocketPathGeneration(): void
    {
        $path = rtorrentScgiSocketPath('alice');
        $this->assertEquals('/home/alice/.rtorrent.socket', $path);

        $path = rtorrentScgiSocketPath('bob123');
        $this->assertEquals('/home/bob123/.rtorrent.socket', $path);
    }

    /**
     * Test ping returns false for non-existent socket.
     */
    public function testPingReturnsFalseForMissingSocket(): void
    {
        $fakePath = '/tmp/pmss-test-nonexistent-' . getmypid() . '.socket';
        $result = rtorrentScgiPing($fakePath, 1);

        $this->assertTrue($result === false, 'Ping should return false for missing socket');
    }

    /**
     * Test ping with empty socket path returns false.
     */
    public function testPingWithEmptyPathReturnsFalse(): void
    {
        $result = rtorrentScgiPing('', 1);
        $this->assertTrue($result === false, 'Ping should return false for empty path');
    }

    /**
     * Test SCGI send returns false for non-existent socket.
     */
    public function testScgiSendReturnsFalseForMissingSocket(): void
    {
        $fakePath = '/tmp/pmss-test-nonexistent-' . getmypid() . '.socket';
        $result = rtorrentScgiSend($fakePath, 'test', 1);

        $this->assertTrue($result === false, 'Send should return false for missing socket');
    }

    /**
     * Test complete request/response cycle formatting.
     */
    public function testCompleteRequestFormatting(): void
    {
        $method = 'system.hostname';
        $xmlrpc = rtorrentScgiFormatXmlrpcCall($method);
        $request = rtorrentScgiFormatRequest($xmlrpc);

        // Verify the full request structure.
        $this->assertStringContainsString('CONTENT_LENGTH', $request);
        $this->assertStringContainsString('system.hostname', $request);
        $this->assertStringContainsString('methodCall', $request);

        // Should be sendable (well-formed).
        $this->assertTrue(strlen($request) > 50, 'Complete request should be substantial');
    }

    /**
     * Test request header uses null bytes as separators.
     */
    public function testRequestHeaderUsesNullByteSeparators(): void
    {
        $request = rtorrentScgiFormatRequest('data');

        // SCGI protocol uses null bytes between key-value pairs.
        $nullCount = substr_count($request, "\x00");
        $this->assertTrue(
            $nullCount >= 4,
            'Header should contain at least 4 null bytes (CONTENT_LENGTH, value, SCGI, 1)'
        );
    }
}
