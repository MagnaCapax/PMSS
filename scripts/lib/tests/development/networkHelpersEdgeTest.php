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
        $commands = \networkLoadMonitoringCommands(
            static function (array &$output, int &$rc): void {
                $output = [
                    'iptables -A OUTPUT -m owner --uid-owner 1000 -j ACCEPT',
                    '/sbin/iptables -F OUTPUT',
                    'iptables -A INPUT -j ACCEPT; rm -rf /',
                ];
                $rc = 0;
            }
        );

        $this->assertEquals(
            ['-A OUTPUT -m owner --uid-owner 1000 -j ACCEPT'],
            $commands
        );
    }

    public function testLoadMonitoringCommandsRejectsFailedHelperOutput(): void
    {
        $messages = [];
        $commands = \networkLoadMonitoringCommands(
            static function (array &$output, int &$rc): void {
                $output = ['iptables -A OUTPUT -m owner --uid-owner 1000 -j ACCEPT'];
                $rc = 1;
            },
            static function (string $message) use (&$messages): void {
                $messages[] = $message;
            }
        );

        $this->assertSame([], $commands);
        $this->assertStringContainsString('monitoring rules helper failed', implode("\n", $messages));
    }

    public function testLoadMonitoringCommandsRejectsRunnerExceptions(): void
    {
        $messages = [];
        $commands = \networkLoadMonitoringCommands(
            static function (array &$output, int &$rc): void {
                throw new \RuntimeException('boom');
            },
            static function (string $message) use (&$messages): void {
                $messages[] = $message;
            }
        );

        $this->assertSame([], $commands);
        $this->assertStringContainsString('failed to run monitoring rules helper', implode("\n", $messages));
    }
}
