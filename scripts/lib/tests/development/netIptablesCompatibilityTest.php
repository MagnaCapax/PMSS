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
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'scripts/lib/net/iptables.php',
            [
                "require_once __DIR__.'/../network/iptables.php';",
                'networkRunIptables($rule);',
                'return networkParseMonitoringCommands($raw);',
                'return networkApplyIptablesAtomically($filterCommands, $natCommands);',
                'networkApplyIptablesFallback($filterCommands, $natCommands, $replacements);',
            ],
            [
                "if (!function_exists('iptablesRun')) {" => 'Legacy iptables shim should rely on require_once instead of project-function guards',
                "if (!function_exists('iptablesParseMonitoring')) {" => 'Legacy iptables shim should rely on require_once instead of project-function guards',
                "if (!function_exists('iptablesApplyAtomically')) {" => 'Legacy iptables shim should rely on require_once instead of project-function guards',
                "if (!function_exists('iptablesApplyFallback')) {" => 'Legacy iptables shim should rely on require_once instead of project-function guards',
            ]
        );
    }
}
