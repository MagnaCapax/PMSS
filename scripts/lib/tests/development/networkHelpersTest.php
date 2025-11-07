<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/network/iptables.php';

class NetworkHelpersTest extends TestCase
{
    public function testParseMonitoringCommandsStripsBinaryPrefix(): void
    {
        $raw = "/sbin/iptables -A INPUT -s 10.0.0.0/8 -j DROP\n".
               "iptables -F INPUT\n".
               "  /sbin/iptables   -A OUTPUT -j ACCEPT\n";
        $result = \networkParseMonitoringCommands($raw);
        $this->assertEquals(['-A INPUT -s 10.0.0.0/8 -j DROP','-A OUTPUT -j ACCEPT'], $result);
    }

    public function testParseMonitoringCommandsHandlesEmptyString(): void
    {
        $this->assertEquals([], \networkParseMonitoringCommands(''));
    }
}
