<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/network/iptables.php';

class NetworkHelpersTest extends TestCase
{
    public function testParseMonitoringCommandCases(): void
    {
        foreach ([
            [
                "/sbin/iptables -A INPUT -s 10.0.0.0/8 -j DROP\n".
                "iptables -F INPUT\n".
                "  /sbin/iptables   -A OUTPUT -j ACCEPT\n",
                ['-A INPUT -s 10.0.0.0/8 -j DROP', '-A OUTPUT -j ACCEPT'],
                'binary prefixes and flushes',
            ],
            ['', [], 'empty input'],
            [
                "/sbin/iptables -A OUTPUT -j ACCEPT; touch /tmp/pmss-bad\n".
                "/sbin/iptables -A OUTPUT -m owner --uid-owner 1001 -j ACCEPT\n".
                "echo unexpected\n".
                "/sbin/iptables -A OUTPUT -j ACCEPT $(id)\n",
                ['-A OUTPUT -m owner --uid-owner 1001 -j ACCEPT'],
                'shell control tokens',
            ],
        ] as [$raw, $expected, $label]) {
            $this->assertEquals($expected, \networkParseMonitoringCommands($raw), 'parse case failed: '.$label);
        }
    }

    public function testParseMonitoringCommandsIgnoresCommentsAndFlush(): void
    {
        $raw = "# comment\n".
               " /sbin/iptables    -F INPUT\n".
               " /sbin/iptables -A FORWARD -j ACCEPT\n".
               "iptables   -t nat   -A POSTROUTING -j MASQUERADE\n";

        $this->assertEquals([
            '-A FORWARD -j ACCEPT',
            '-t nat   -A POSTROUTING -j MASQUERADE',
        ], \networkParseMonitoringCommands($raw));
    }

    public function testParseMonitoringCommandsAcceptsAllLegacyPrefixes(): void
    {
        $raw = "iptables -A INPUT -j ACCEPT\n".
            "iptables   -A OUTPUT -j ACCEPT\n".
            "sbin/iptables -A FORWARD -j ACCEPT\n".
            "/sbin/iptables -t nat -A POSTROUTING -j MASQUERADE\n";

        $this->assertEquals([
            '-A INPUT -j ACCEPT',
            '-A OUTPUT -j ACCEPT',
            '-A FORWARD -j ACCEPT',
            '-t nat -A POSTROUTING -j MASQUERADE',
        ], \networkParseMonitoringCommands($raw));
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

    public function testLoadMonitoringCommandsFiltersFlushAndUnsafeHelperLines(): void
    {
        $this->assertEquals(
            ['-A OUTPUT -m owner --uid-owner 1000 -j ACCEPT'],
            \networkLoadMonitoringCommands(static function (array &$output, int &$rc): void {
                $output = [
                    'iptables -A OUTPUT -m owner --uid-owner 1000 -j ACCEPT',
                    '/sbin/iptables -F OUTPUT',
                    'iptables -A INPUT -j ACCEPT; rm -rf /',
                ];
                $rc = 0;
            })
        );
    }

    public function testLoadMonitoringCommandsSkipsUnavailableHelpers(): void
    {
        foreach ([
            [
                static function (array &$output, int &$rc): void { $output = ['/sbin/iptables -A OUTPUT -j ACCEPT']; $rc = 3; },
                ['setupNetwork: monitoring rules helper failed (rc=3); skipping per-user monitoring rules'],
            ],
            [
                static function (array &$output, int &$rc): void { throw new \RuntimeException('helper unavailable'); },
                ['setupNetwork: failed to run monitoring rules helper: helper unavailable'],
            ],
        ] as [$runner, $expectedLogs]) {
            $logs = [];
            $result = \networkLoadMonitoringCommands($runner, static function (string $message) use (&$logs): void { $logs[] = $message; });

            $this->assertEquals([], $result);
            $this->assertSame($expectedLogs, $logs);
        }
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

    public function testFallbackRenderRejectsUnsafeInputsBeforeFlush(): void
    {
        foreach ([
            [
                ['-A INPUT -i ##IFACE## -j ACCEPT'],
                [],
                ['##IFACE##' => 'eth0; touch /tmp/pmss-bad'],
                'unsafe replacement',
            ],
            [
                [['not' => 'a rule']],
                [],
                ['##IFACE##' => 'eth0'],
                'non-string rule',
            ],
        ] as [$filterCommands, $natCommands, $replacements, $label]) {
            $this->assertSame(
                null,
                \networkIptablesFallbackRenderedCommands($filterCommands, $natCommands, $replacements),
                'expected fallback render rejection for '.$label
            );
        }
    }

    public function testTrafficLogParsesMonitoringRulesBeforeApplying(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/cron/trafficLog.php', ['networkParseMonitoringCommands(', "networkRunIptables('-F OUTPUT');"]);
        $this->pmssAssertRepoFileNotContainsString('scripts/cron/trafficLog.php', 'passthru($'.'monitoringRules)');
    }
}
