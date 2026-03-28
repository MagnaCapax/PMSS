<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/net/iptables.php';

class NetIptablesCompatibilityTest extends TestCase
{
    public function testLegacyParseWrapperMatchesCanonicalParser(): void
    {
        $raw = "# comment\n".
            "iptables -A INPUT -s 10.0.0.0/8 -j DROP\n".
            "  /sbin/iptables   -A OUTPUT -j ACCEPT\n";

        $this->assertSame(\networkParseMonitoringCommands($raw), \iptablesParseMonitoring($raw));
    }

    public function testLegacyLibraryDelegatesToCanonicalNetworkHelpers(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/net/iptables.php');

        $this->assertStringContainsString("require_once __DIR__.'/../network/iptables.php';", $source);
        $this->assertStringContainsString('networkRunIptables($rule);', $source);
        $this->assertStringContainsString('return networkParseMonitoringCommands($raw);', $source);
        $this->assertStringContainsString('return networkApplyIptablesAtomically($filterCommands, $natCommands);', $source);
        $this->assertStringContainsString('networkApplyIptablesFallback($filterCommands, $natCommands, $replacements);', $source);
    }
}
