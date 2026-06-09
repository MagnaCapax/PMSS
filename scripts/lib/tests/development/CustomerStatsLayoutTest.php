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
        $this->pmssAssertRepoFileContainsAllStrings('etc/skel/www/statsHelpers.php', array(
            'class="stats-block resource-summary-block"',
            'class="resource-summary-strip"',
            'class="resource-summary-label">CPU</span>',
            'class="resource-summary-label">Memory</span>',
            'class="resource-summary-label">Processes</span>',
        ));
        $this->pmssAssertRepoFileNotContainsStrings('etc/skel/www/stats.php', array(
            '<h6>CPU usage</h6>',
            '<h6>Memory usage</h6>',
            '<h6>Process count</h6>',
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
        $this->pmssAssertRepoFileContainsAllStrings('etc/skel/www/stats.php', array(
            "require_once __DIR__.'/scriptsInc.php';",
            "require_once __DIR__.'/statsHelpers.php';",
            'pmssStatsRenderResourceBlocks($resourceState);',
        ));
        $this->pmssAssertRepoFileContainsAllStrings('etc/skel/www/statsHelpers.php', array(
            'function pmssStatsDockerInactiveNote(',
            'function pmssStatsRenderLineChart(',
            'function pmssStatsRenderResourceBlocks(',
        ));
        $this->pmssAssertRepoFileNotContainsString('etc/skel/www/stats.php', 'function pmssStatsSerializedStateRead(');
        $this->pmssAssertRepoFileNotContainsString('etc/skel/www/stats.php', 'PMSS_STATS'.'_HELPERS_ONLY');
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
