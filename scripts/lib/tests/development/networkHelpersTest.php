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

    public function testParseMonitoringCommandsRejectsShellControlTokens(): void
    {
        $raw = "/sbin/iptables -A OUTPUT -j ACCEPT; touch /tmp/pmss-bad\n".
            "/sbin/iptables -A OUTPUT -m owner --uid-owner 1001 -j ACCEPT\n".
            "echo unexpected\n".
            "/sbin/iptables -A OUTPUT -j ACCEPT $(id)\n";

        $this->assertEquals(
            ['-A OUTPUT -m owner --uid-owner 1001 -j ACCEPT'],
            \networkParseMonitoringCommands($raw)
        );
    }

    public function testTrafficLogParsesMonitoringRulesBeforeApplying(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/cron/trafficLog.php', 'networkParseMonitoringCommands(');
        $this->pmssAssertRepoFileContainsString('scripts/cron/trafficLog.php', "networkRunIptables('-F OUTPUT');");
        $this->pmssAssertRepoFileNotContainsString('scripts/cron/trafficLog.php', 'passthru($'.'monitoringRules)');
    }
}
