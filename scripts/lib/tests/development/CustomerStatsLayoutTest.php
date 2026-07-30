<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/scriptsInc.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/statsHelpers.php';

final class CustomerStatsLayoutTest extends TestCase
{
    public function testResourceBasicsRenderAsCompactTopSnapshot(): void
    {
        $welcome = $this->pmssRenderCustomerPanelPage('welcome.php');
        $stats = $this->pmssRenderCustomerPanelPage('stats.php');

        $this->assertOrderedStrings(
            array('<div class="pmss-bonus-banner" role="status">', 'BONUS: +50 GiB', 'Bonus is applied on this server.', '<div class="portfolioimg">'),
            $welcome,
            'Missing welcome bonus layout marker: ',
            'Welcome bonus order changed at: '
        );
        $this->assertOrderedStrings(
            array('<div class="stats-block stats-bonus-block" role="status">', 'BONUS: +50 GiB', 'Bonus is applied on this server.', '<h6>Base Resources (current)</h6>'),
            $stats,
            'Missing stats bonus layout marker: ',
            'Stats bonus order changed at: '
        );

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
                    'class="stats-block stats-bonus-block"',
                    'pmssCustomerBonusDisplayTextBuild($pmssBonusDisplayState)',
                    'pmssCustomerBonusDisplayNoteBuild($pmssBonusDisplayState)',
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
            'etc/skel/www/scriptsInc.php' => array('required' => array(
                'function pmssCustomerBonusDisplayStateRead(',
                'function pmssCustomerBonusDisplayTextBuild(',
                'function pmssCustomerBonusDisplayNoteBuild(',
            )),
        ));
    }

    public function testBonusDisplayPrefersPercentAndPreservesGiBFallback(): void
    {
        $root = $this->pmssMakeTempDir('pmss-bonus-display-');
        $cases = array(
            array('percent', '47', '1925', array('unit' => 'percent', 'value' => 47, 'state' => 'applied'), 'BONUS: 47%', 'Bonus is applied on this server.'),
            array('zero-percent', '0', '1925', array('unit' => 'percent', 'value' => 0, 'state' => 'zero'), 'BONUS: 0%', 'No bonus is applied on this server.'),
            array('gib-fallback', null, '1925', array('unit' => 'gib', 'value' => 1925, 'state' => 'applied'), 'BONUS: +1,925 GiB', 'Bonus is applied on this server.'),
            array('invalid-percent', '-1', '1925', array('unit' => 'gib', 'value' => 1925, 'state' => 'applied'), 'BONUS: +1,925 GiB', 'Bonus is applied on this server.'),
            array('missing-values', null, null, array('unit' => 'percent', 'value' => 0, 'state' => 'absent'), 'BONUS: 0%', 'No bonus is applied on this server yet. Earned bonus is added on top of your base resources, never taken from them.'),
        );

        foreach ($cases as $case) {
            $userBonusPath = $root.'/'.$case[0].'.userBonus';
            $bonusQuotaPath = $root.'/'.$case[0].'.bonusQuota';
            if ($case[1] !== null) $this->pmssWriteFile($userBonusPath, $case[1]."\n");
            if ($case[2] !== null) $this->pmssWriteFile($bonusQuotaPath, $case[2]."\n");

            $state = pmssCustomerBonusDisplayStateRead($userBonusPath, $bonusQuotaPath);
            $this->assertSame($case[3], $state, 'Unexpected bonus state for '.$case[0]);
            $this->assertSame($case[4], pmssCustomerBonusDisplayTextBuild($state), 'Unexpected bonus text for '.$case[0]);
            $this->assertSame($case[5], pmssCustomerBonusDisplayNoteBuild($state), 'Unexpected bonus note for '.$case[0]);
        }
    }

    public function testStatsPageLoadsBundledHelperLibrary(): void
    {
        $this->pmssAssertRepoFileContractCases(array(
            'etc/skel/www/stats.php' => array(
                'required' => array(
                    '$pmssStatsRequiredHelpers',
                    'Stats unavailable: missing local panel helper',
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

    public function testStatsPageFailsSoftWhenBundledHelperIsMissing(): void
    {
        $this->pmssLoadCustomerPanelRenderHarness();
        $runRoot = \pmssCustomerPanelRenderTempRoot();
        $homeRoot = $runRoot.'/home';
        $home = $homeRoot.'/renderuser';
        $www = $home.'/www';
        $bootstrap = $runRoot.'/php-cli-bootstrap.php';

        try {
            $setup = \pmssCustomerPanelRenderPrepare($this->pmssRepoPath('etc/skel/www'), $home, $www, $bootstrap);
            $this->assertTrue($setup['ok'], $setup['error']);
            $this->assertTrue(@unlink($www.'/statsHelpers.php'), 'Expected stats helper fixture removal to succeed.');

            foreach (array('stats.php', 'info.php') as $page) {
                $result = \pmssCustomerPanelRenderPage($www, $bootstrap, $homeRoot, $home, $page, array('minBytes' => 1, 'query' => ''));
                $this->assertSame(array(), $result['errors'], implode('; ', $result['errors']));
                $this->assertStringContainsString('Stats unavailable: missing local panel helper statsHelpers.php.', $result['stdout']);
                $this->assertStringNotContainsString('Fatal error', $result['stdout'].$result['stderr']);
            }
        } finally {
            \pmssCustomerPanelRenderCleanup($runRoot);
        }
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
