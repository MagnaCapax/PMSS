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

        $this->assertStringContainsAllStrings(["require_once __DIR__.'/../network/iptables.php';", 'networkRunIptables($rule);', 'return networkParseMonitoringCommands($raw);', 'return networkApplyIptablesAtomically($filterCommands, $natCommands);', 'networkApplyIptablesFallback($filterCommands, $natCommands, $replacements);'], $source);
        $this->pmssAssertStringNotContainsString("if (!function_exists('iptablesRun')) {", $source, 'Legacy iptables shim should rely on require_once instead of project-function guards');
        $this->pmssAssertStringNotContainsString("if (!function_exists('iptablesParseMonitoring')) {", $source, 'Legacy iptables shim should rely on require_once instead of project-function guards');
        $this->pmssAssertStringNotContainsString("if (!function_exists('iptablesApplyAtomically')) {", $source, 'Legacy iptables shim should rely on require_once instead of project-function guards');
        $this->pmssAssertStringNotContainsString("if (!function_exists('iptablesApplyFallback')) {", $source, 'Legacy iptables shim should rely on require_once instead of project-function guards');
    }
}
