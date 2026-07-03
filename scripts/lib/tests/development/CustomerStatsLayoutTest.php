<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/scriptsInc.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/statsHelpers.php';

final class CustomerStatsLayoutTest extends TestCase
{
    public function testResourceBasicsRenderAsCompactTopSnapshot(): void
    {
        $stats = $this->pmssRenderCustomerPanelPage('stats.php');

        $this->assertOrderedStrings(
            array('<h6>Resource snapshot</h6>', '<h6>Storage I/O</h6>', '<h6>Memory pressure</h6>'),
            $stats,
            'Missing stats layout marker: ',
            'Resource summary order changed at: '
        );
        $this->pmssAssertRepoFileContractCases(array(
            'etc/skel/www/statsHelpers.php' => array('required' => array(
                'class="stats-block resource-summary-block"',
                'class="resource-summary-strip"',
                'class="resource-summary-label">CPU</span>',
                'class="resource-summary-label">Memory</span>',
                'class="resource-summary-label">Processes</span>',
            )),
            'etc/skel/www/stats.php' => array(
                'required' => array(
                    'class="stats-block stats-block-base-resources"',
                    'class="stats-base-resources-pre"',
                    '.stats-block-base-resources .stats-base-resources-pre',
                    'max-height: none;',
                    'overflow-y: visible;',
                ),
                'forbidden' => array(
                    '<h6>CPU usage</h6>',
                    '<h6>Memory usage</h6>',
                    '<h6>Process count</h6>',
                ),
            ),
        ));
    }

    public function testStatsPageLoadsBundledHelperLibrary(): void
    {
        $this->pmssAssertRepoFileContractCases(array(
            'etc/skel/www/stats.php' => array(
                'required' => array(
                    "require_once __DIR__.'/scriptsInc.php';",
                    "require_once __DIR__.'/statsHelpers.php';",
                    'pmssStatsRenderResourceBlocks($resourceState);',
                ),
                'forbidden' => array('function pmssStatsSerializedStateRead(', 'PMSS_STATS'.'_HELPERS_ONLY'),
            ),
            'etc/skel/www/statsHelpers.php' => array('required' => array(
                'function pmssStatsNetworkInterfaceStatus(',
                'function pmssStatsDockerInactiveNote(',
                'function pmssStatsRenderLineChart(',
                'function pmssStatsRenderResourceBlocks(',
            )),
        ));
    }

    public function testTrafficUsageRendersRawOnlyTrafficSnapshots(): void
    {
        $home = $this->pmssMakeUserHomeTree('pmss-stats-traffic-', 'www');
        $www = $home.'/www';
        $trafficData = array(
            'raw' => array('month' => 1025.0, 'week' => 512.0, 'day' => 2.0),
            'daily' => array('2026-06-01' => 1.0, '2026-06-02' => 2.0),
        );
        $trafficIngressData = array('raw' => array('month' => 2048.0), 'daily' => array());
        $this->pmssWriteSerializedFixture($home.'/.trafficData', $trafficData);
        $this->pmssWriteSerializedFixture($home.'/.trafficDataIngress', $trafficIngressData);

        $cwd = getcwd();
        $this->assertTrue(is_string($cwd), 'Expected current working directory to be available.');
        chdir($www);
        try {
            [, $output] = $this->pmssCaptureStdout(function (): void {
                \pmssStatsRenderTrafficUsageBlock();
            });
        } finally {
            chdir($cwd);
        }

        $this->assertStringContainsString('Week: 512MiB, Day: 2MiB', $output);
        $this->assertStringContainsString('Past 30 days upload traffic: 1GiB', $output);
        $this->assertStringContainsString('Past 30 days inbound traffic: 2GiB', $output);
    }

    public function testTrafficDisplayValueKeepsDisplayAndFallsBackToRaw(): void
    {
        $this->assertSame(
            'operator display',
            \pmssStatsTrafficDisplayValue(array('display' => array('month' => 'operator display'), 'raw' => array('month' => 1025.0)), 'month')
        );
        $this->assertSame('1GiB', \pmssStatsTrafficDisplayValue(array('raw' => array('month' => 1025.0)), 'month'));
        $this->assertSame('1024MiB', \pmssStatsTrafficDisplayValue(array('raw' => array('month' => 1024.0)), 'month'));
        $this->assertSame('1TiB', \pmssStatsTrafficDisplayValue(array('raw' => array('month' => 1048577.0)), 'month'));
        $this->assertSame('n/a', \pmssStatsTrafficDisplayValue(array('raw' => array('month' => 'bad')), 'month'));
    }

    public function testStatsStatusHelpersCharacterizeLocalResourceContracts(): void
    {
        $cgroupDir = $this->pmssMakeTempDir('pmss-stats-cgroup-');
        $this->pmssWriteFile($cgroupDir.'/memory.current', (string) (256 * 1024 * 1024)."\n");
        $this->pmssWriteFile($cgroupDir.'/memory.high', (string) (512 * 1024 * 1024)."\n");
        $this->pmssWriteFile($cgroupDir.'/memory.max', (string) (1024 * 1024 * 1024)."\n");
        $this->pmssWriteFile($cgroupDir.'/pids.current', "7\n");
        $commands = array();
        $runner = function (string $command, string $label) use (&$commands): array {
            $commands[] = $command;
            if ($label === 'User ID') {
                return array('output' => "1001\n", 'error' => null);
            }
            if ($label === 'User process list') {
                return array('output' => " 123     1 Ss   rtorrent        /usr/bin/rtorrent\n", 'error' => null);
            }

            return array('output' => '', 'error' => null);
        };

        $baseResources = \pmssStatsBaseResourcesBuild($runner, array('cgroup_dir' => $cgroupDir));
        $this->assertSame(
            '1001',
            $baseResources['uid']
        );
        $this->assertStringContainsAllStrings(
            array('User slice: user-1001.slice', 'Memory current: 256.0 MiB', 'Memory high: 512.0 MiB', 'Memory max: 1.0 GiB', 'Tasks current: 7', 'Processes:', '/usr/bin/rtorrent'),
            $baseResources['text']
        );
        foreach ($commands as $command) {
            $this->assertStringNotContainsString('systemctl status', $command);
            $this->assertStringNotContainsString('systemctl show', $command);
        }

        $interfacesRoot = $this->pmssMakeTempDir('pmss-stats-net-');
        mkdir($interfacesRoot.'/wg0');
        $statusCommands = array();
        $statusRunner = function (string $command, string $label) use (&$statusCommands): array {
            $statusCommands[] = $command;
            $outputs = array('App status' => "alice rtorrent\nbob deluged\ncarol rclone\n", 'Docker status' => '');
            return array('output' => $outputs[$label] ?? '', 'error' => null);
        };

        $this->assertSame(
            array(
                'wgStatus' => 'active',
                'ovpnStatus' => 'inactive',
                'apps' => array(
                    'rTorrent' => 'active', 'qBittorrent' => 'stopped', 'Deluge' => 'active', 'rclone' => 'active', 'Docker' => 'active',
                ),
                'dockerInactiveNote' => '',
            ),
            \pmssStatsStatusModelBuild('999999', null, $statusRunner, array('network_interfaces_root' => $interfacesRoot))
        );
        foreach ($statusCommands as $command) {
            $this->assertStringNotContainsString('systemctl is-active', $command);
        }
    }

    public function testStatsVpnStatusUsesInterfacePresenceInsteadOfSystemctlOutput(): void
    {
        $interfacesRoot = $this->pmssMakeTempDir('pmss-stats-vpn-net-');
        $runner = function (string $command, string $label): array {
            $outputs = array('App status' => '', 'Docker status' => 'Cannot connect to the Docker daemon');
            return array('output' => $outputs[$label] ?? "active\n", 'error' => null);
        };
        $status = function () use ($runner, $interfacesRoot): array {
            return \pmssStatsStatusModelBuild('999999', null, $runner, array('network_interfaces_root' => $interfacesRoot));
        };

        $this->assertSame('inactive', $status()['wgStatus']);
        $this->assertSame('inactive', $status()['ovpnStatus']);

        mkdir($interfacesRoot.'/wg0');
        $this->assertSame('active', $status()['wgStatus']);
        $this->assertSame('inactive', $status()['ovpnStatus']);

        mkdir($interfacesRoot.'/tun0');
        $this->assertSame('active', $status()['wgStatus']);
        $this->assertSame('active', $status()['ovpnStatus']);
    }
}
