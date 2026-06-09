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
            'etc/skel/www/stats.php' => array('forbidden' => array(
                '<h6>CPU usage</h6>',
                '<h6>Memory usage</h6>',
                '<h6>Process count</h6>',
            )),
        ));
    }

    public function testBaseResourcesCgroupOutputIsNotHeightClipped(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('etc/skel/www/stats.php', array(
            'class="stats-block stats-block-base-resources"',
            'class="stats-base-resources-pre"',
            '.stats-block-base-resources .stats-base-resources-pre',
            'max-height: none;',
            'overflow-y: visible;',
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

    public function testStatsStatusHelpersCharacterizeShellDerivedContracts(): void
    {
        $runner = function (string $command, string $label): array {
            return $label === 'User ID'
                ? array('output' => "1001\n", 'error' => null)
                : array('output' => "Loaded: loaded\nCGroup:\n /user.slice\nStatus: ok", 'error' => null);
        };

        $this->assertSame(
            array('uid' => '1001', 'text' => "Loaded: loaded\nStatus: ok\nCGroup:\n /user.slice"),
            \pmssStatsBaseResourcesBuild($runner)
        );

        $statusRunner = function (string $command, string $label): array {
            $outputs = array('WireGuard status' => "active\n", 'OpenVPN status' => "inactive\n", 'App status' => "alice rtorrent\nbob deluged\ncarol rclone\n", 'Docker status' => '');
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
            \pmssStatsStatusModelBuild('999999', null, $statusRunner)
        );
    }
}
