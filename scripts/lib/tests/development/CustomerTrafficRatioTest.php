<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/userTrafficLimit.php';

final class CustomerTrafficRatioTest extends TestCase
{
    public function testRatioStateLocksMonthlyDisplayThresholds(): void
    {
        $this->assertSame(
            array(
                'good' => array('available' => true, 'display' => '2.00:1', 'class' => 'good', 'color' => '#81c784'),
                'warn' => array('available' => true, 'display' => '1.50:1', 'class' => 'warn', 'color' => '#ffb74d'),
                'bad' => array('available' => true, 'display' => '0.50:1', 'class' => 'bad', 'color' => '#ef5350'),
                'zero' => array('available' => true, 'display' => 'N/A', 'class' => 'na', 'color' => '#b0bec5'),
                'missing' => array('available' => false, 'display' => '', 'class' => '', 'color' => ''),
            ),
            array(
                'good' => \pmssTrafficRatioStateBuild(1024, 2048),
                'warn' => \pmssTrafficRatioStateBuild(1024, 1536),
                'bad' => \pmssTrafficRatioStateBuild(2048, 1024),
                'zero' => \pmssTrafficRatioStateBuild(0, 1024),
                'missing' => \pmssTrafficRatioStateBuild(null, 1024),
            )
        );
    }

    public function testWelcomeAndStatsUseSharedTrafficRatioState(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'etc/skel/www/welcome.php' => [
                'required' => ['pmssTrafficRatioStateBuild($outboundMonth, $inboundMonth)', '$ratioState[\'color\']'],
                'forbidden' => ['$ratio >= 2.0', '$ratio >= 1.0', '$ratio'.'Color'],
            ],
            'etc/skel/www/statsHelpers.php' => [
                'required' => ['pmssTrafficRatioStateBuild($trafficOutboundMonth, $trafficInboundMonth)', '$trafficRatioState[\'class\']'],
                'forbidden' => ['$trafficRatio'.'GoodMin', '$trafficRatio'.'WarnMin', '$trafficRatio >='],
            ],
        ]);
    }
}
