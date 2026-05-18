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

    public function testLoadMonitoringCommandsParsesSuccessfulHelperOutput(): void
    {
        $result = \networkLoadMonitoringCommands(
            static function (array &$output, int &$rc): void {
                $output = [
                    '/sbin/iptables -A OUTPUT -m owner --uid-owner 1001 -j ACCEPT',
                    '/sbin/iptables -A OUTPUT -j ACCEPT; touch /tmp/pmss-bad',
                ];
                $rc = 0;
            }
        );

        $this->assertEquals(['-A OUTPUT -m owner --uid-owner 1001 -j ACCEPT'], $result);
    }

    public function testLoadMonitoringCommandsSkipsFailedHelperOutput(): void
    {
        $logs = [];
        $result = \networkLoadMonitoringCommands(
            static function (array &$output, int &$rc): void {
                $output = ['/sbin/iptables -A OUTPUT -j ACCEPT'];
                $rc = 3;
            },
            static function (string $message) use (&$logs): void {
                $logs[] = $message;
            }
        );

        $this->assertEquals([], $result);
        $this->assertSame(['setupNetwork: monitoring rules helper failed (rc=3); skipping per-user monitoring rules'], $logs);
    }

    public function testLoadMonitoringCommandsSkipsHelperExceptions(): void
    {
        $logs = [];
        $result = \networkLoadMonitoringCommands(
            static function (array &$output, int &$rc): void {
                throw new \RuntimeException('helper unavailable');
            },
            static function (string $message) use (&$logs): void {
                $logs[] = $message;
            }
        );

        $this->assertEquals([], $result);
        $this->assertSame(['setupNetwork: failed to run monitoring rules helper: helper unavailable'], $logs);
    }

    public function testIptablesCommandSafetyRejectsNullBytes(): void
    {
        $this->assertFalse(\networkIptablesCommandSafe("-A OUTPUT\0 -j ACCEPT"));
    }

    public function testAtomicApplyRejectsUnsafeRulesBeforeRestore(): void
    {
        $this->assertFalse(\networkApplyIptablesAtomically(["-A OUTPUT\0 -j ACCEPT"], []));
        $this->assertFalse(\networkApplyIptablesAtomically(['-A OUTPUT -j ACCEPT'], [['not' => 'a rule']]));
    }

    public function testFallbackRenderValidatesRulesBeforeFlush(): void
    {
        $this->assertSame(
            [
                '-A INPUT -i eth0 -j ACCEPT',
                '-t nat -A POSTROUTING -o eth0 -j MASQUERADE',
            ],
            \networkIptablesFallbackRenderedCommands(
                ['-A INPUT -i ##IFACE## -j ACCEPT'],
                ['-A POSTROUTING -o ##IFACE## -j MASQUERADE'],
                ['##IFACE##' => 'eth0']
            )
        );
    }

    public function testFallbackRenderRejectsUnsafeReplacementBeforeFlush(): void
    {
        $this->assertSame(
            null,
            \networkIptablesFallbackRenderedCommands(
                ['-A INPUT -i ##IFACE## -j ACCEPT'],
                [],
                ['##IFACE##' => 'eth0; touch /tmp/pmss-bad']
            )
        );
    }

    public function testFallbackRenderRejectsNonStringRuleBeforeFlush(): void
    {
        $this->assertSame(
            null,
            \networkIptablesFallbackRenderedCommands(
                [['not' => 'a rule']],
                [],
                ['##IFACE##' => 'eth0']
            )
        );
    }

    public function testTrafficLogParsesMonitoringRulesBeforeApplying(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/cron/trafficLog.php', 'networkParseMonitoringCommands(');
        $this->pmssAssertRepoFileContainsString('scripts/cron/trafficLog.php', "networkRunIptables('-F OUTPUT');");
        $this->pmssAssertRepoFileNotContainsString('scripts/cron/trafficLog.php', 'passthru($'.'monitoringRules)');
    }
}
