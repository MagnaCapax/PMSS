<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/network/setup.php';

/** Freeze network setup policy bytes and uplink decisions without touching networking. */
class NetworkSetupCharacterizationTest extends TestCase
{
    public function testFirewallRuleOrderAndMonitoringSuffixStayExact(): void
    {
        // Literal snapshot of the pre-extraction filter policy, including overlapping overlay rules.
        $expected = <<<'RULES'
-A INPUT -p tcp --tcp-flags SYN SYN -m tcpmss --mss 1:500 -m limit --limit 2/second --limit-burst 10 -j LOG --log-prefix "tcpsack: " --log-level 4
-A INPUT -p tcp --tcp-flags SYN SYN -m tcpmss --mss 1:500 -j DROP
-A INPUT -i ##IFACE## -s 0.0.0.0/8 -j DROP
-A INPUT -i ##IFACE## -s 100.64.0.0/10 -j DROP
-A INPUT -i ##IFACE## -s 127.0.0.0/8 -j DROP
-A INPUT -i ##IFACE## -s 169.254.0.0/16 -j DROP
-A INPUT -i ##IFACE## -s 172.16.0.0/12 -j DROP
-A INPUT -i ##IFACE## -s 192.0.0.0/24 -j DROP
-A INPUT -i ##IFACE## -s 192.0.2.0/24 -j DROP
-A INPUT -i ##IFACE## -s 192.168.0.0/16 -j DROP
-A INPUT -i ##IFACE## -s 198.18.0.0/15 -j DROP
-A INPUT -i ##IFACE## -s 198.51.100.0/24 -j DROP
-A INPUT -i ##IFACE## -s 203.0.113.0/24 -j DROP
-A INPUT -i ##IFACE## -s 224.0.0.0/3 -j DROP
-A INPUT -i ##IFACE## -m state --state NEW -p udp --dport 1194 -j ACCEPT
-A INPUT -i ##IFACE## -m state --state NEW -p udp --dport 51820 -j ACCEPT
-A INPUT -i ##IFACE## -p tcp --dport 8080 -j DROP
-A INPUT -i ##IFACE## -p tcp --dport 8112 -j DROP
-A INPUT -i tun+ -j ACCEPT
-A INPUT -i wg+ -j ACCEPT
-A FORWARD -i tun+ -o tun+ -j DROP
-A FORWARD -i tun+ -j ACCEPT
-A FORWARD -i tun+ -o ##IFACE## -m state --state RELATED,ESTABLISHED -j ACCEPT
-A FORWARD -i ##IFACE## -o tun+ -m state --state RELATED,ESTABLISHED -j ACCEPT
-A FORWARD -i wg0 -o wg0 -j DROP
-A FORWARD -i wg+ -o ##IFACE## -j ACCEPT
-A FORWARD -i ##IFACE## -o wg+ -m state --state RELATED,ESTABLISHED -j ACCEPT
-A OUTPUT -o tun+ -j ACCEPT
-A OUTPUT -o wg+ -j ACCEPT
RULES;
        foreach ([[], ['-A OUTPUT -j ACCEPT'], ['-A OUTPUT -j ACCEPT', '-A OUTPUT -j ACCEPT'],
            ['-A OUTPUT -j MARK --set-mark 1001', '-A OUTPUT -j ACCEPT'], ['unsafe; rule']] as $monitoring) {
            $commands = \networkSetupFirewallCommands($monitoring);
            $this->assertSame(['filter', 'nat'], array_keys($commands));
            $this->assertSame($expected.($monitoring === [] ? '' : "\n".implode("\n", $monitoring)), implode("\n", $commands['filter']));
            $this->assertSame(['-A POSTROUTING -s 10.8.0.0/24 -o ##LINK## -j MASQUERADE',
                '-A POSTROUTING -s 10.90.90.0/24 -o ##LINK## -j MASQUERADE'], $commands['nat']);
        }
    }

    public function testUplinkSelectionProbeOrderAndDiagnosticsStayExact(): void
    {
        $warning = 'setupNetwork: detected eth0 link speed 10000Mbit exceeds configured FireQOS speed 1000Mbit; keeping configured cap';
        foreach ([
            [['interface' => ' eth0 ', 'speed' => 1000], 'ignored', '', ['eth0', 1000], '', [$warning], ['normalize:eth0', 'speed:eth0', 'detected:eth0']],
            [[], 'eth0', '', ['eth0', 1000], '', [], ['primary', 'speed:eth0', 'detected:eth0']],
            [[], 'wg0', "default dev tun0\ndefault dev bad!\ndefault dev eth1\ndefault dev eth2", ['eth1', 1000], '',
                ['setupNetwork: detected tunnel uplink wg0; using eth1 instead'], ['primary', 'routes', 'normalize:tun0', 'normalize:bad!', 'normalize:eth1', 'speed:eth1', 'detected:eth1']],
            [[], 'wg0', '', ['wg0', 1000], '', [], ['primary', 'routes', 'speed:wg0', 'detected:wg0']],
            [['interface' => 'wg0'], '', '', ['wg0', 1000], '', [], ['normalize:wg0', 'speed:wg0', 'detected:wg0']],
            [['interface' => 'bad!'], '', '', null, "Error: Unsafe configured network interface\n", ['setupNetwork: unsafe configured interface name'], ['normalize:bad!']],
            [[], '', '', null, "Error: Could not determine primary interface\n", [], ['primary']],
        ] as [$config, $primary, $routes, $result, $stdout, $logs, $calls]) {
            $script = <<<'PHP'
namespace NetworkSetupFixture;
function detectPrimaryInterface() { $GLOBALS['calls'][] = 'primary'; return $GLOBALS['input']['primary']; }
function networkInterfaceNameNormalized($name) {
    $GLOBALS['calls'][] = 'normalize:'.$name;
    return $name === 'bad!' ? '' : $name;
}
function shell_exec($command) {
    if ($command !== '/sbin/ip route show default 2>/dev/null') throw new \RuntimeException('unexpected route command');
    $GLOBALS['calls'][] = 'routes'; return $GLOBALS['input']['routes'];
}
function getLinkSpeed($name) { $GLOBALS['calls'][] = 'speed:'.$name; return 1000; }
function getDetectedLinkSpeed($name) { $GLOBALS['calls'][] = 'detected:'.$name; return 10000; }
function logMessage($message) { $GLOBALS['logs'][] = $message; }
PHP;
            $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/network/setup.php', 'NetworkSetupFixture');
            $script .= '$GLOBALS["input"] = '.var_export(compact('config', 'primary', 'routes'), true).';';
            $script .= <<<'PHP'
$GLOBALS['calls'] = $GLOBALS['logs'] = [];
$GLOBALS['result'] = null;
ob_start();
// A shutdown capture also freezes the legacy die(message) success exit and stdout.
register_shutdown_function(static function () {
    echo json_encode(['result' => $GLOBALS['result'], 'stdout' => ob_get_clean(), 'logs' => $GLOBALS['logs'], 'calls' => $GLOBALS['calls']]);
});
$GLOBALS['result'] = networkSetupUplinkRead($GLOBALS['input']['config']);
PHP;
            $this->assertSame(compact('result', 'stdout', 'logs', 'calls'), $this->pmssRunInlinePhpJson($script));
        }
    }
}
