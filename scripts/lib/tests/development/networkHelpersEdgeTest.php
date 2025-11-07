<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/network/iptables.php';

class NetworkHelpersEdgeTest extends TestCase
{
    public function testParseMonitoringCommandsIgnoresCommentsAndFlush(): void
    {
        $raw = "# comment\n".
               " /sbin/iptables    -F INPUT\n".
               " /sbin/iptables -A FORWARD -j ACCEPT\n".
               "iptables   -t nat   -A POSTROUTING -j MASQUERADE\n";
        $parsed = \networkParseMonitoringCommands($raw);
        $this->assertEquals([
            '-A FORWARD -j ACCEPT',
            '-t nat   -A POSTROUTING -j MASQUERADE'
        ], $parsed);
    }
}
