<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** The Welcome status strip shows the account's configured CPU limit and CPU / disk I/O priority (issue ask). */
final class WelcomePerformanceMetricTest extends TestCase
{
    /** Render the whole page with the given account configuration written to ~/.config/pmss-user.json. */
    private function renderWelcome(?array $userConfig): string
    {
        return $this->pmssWithCustomerPanelRender(function (string $home, callable $render) use ($userConfig): string {
            if ($userConfig !== null) {
                $this->pmssWriteFile($home.'/.config/pmss-user.json', (string) json_encode($userConfig));
            }
            $expectations = \pmssCustomerPanelRenderExpectations();
            return $render('welcome.php', $expectations['welcome.php'])['stdout'];
        });
    }

    public function testQuotaAndBothPrioritiesRenderAsOneCard(): void
    {
        $html = $this->renderWelcome(array('cpuQuotaPercent' => 400, 'CPUWeight' => 200, 'IOWeight' => 150));

        $this->assertStringContainsAllStrings(array(
            '<div class="h-metric-l">CPU and disk</div>',
            '<div class="h-metric-v">4<span>CPU threads</span></div>',
            'CPU priority 200 · disk I/O priority 150',
            'Priority only matters when the server is busy',
        ), $html);
        $this->assertSame(1, substr_count($html, '<div class="h-metric-l">CPU and disk</div>'));
    }

    public function testOneHundredPercentIsOneThreadAndFractionsAreKept(): void
    {
        $this->assertStringContainsString('<div class="h-metric-v">1<span>CPU thread</span></div>',
            $this->renderWelcome(array('cpuQuotaPercent' => '100')));
        $this->assertStringContainsString('<div class="h-metric-v">2.5<span>CPU threads</span></div>',
            $this->renderWelcome(array('cpuQuotaPercent' => 250)));
    }

    public function testInfinityZeroAndMissingValuesHideTheirPart(): void
    {
        $html = $this->renderWelcome(array('cpuQuotaPercent' => 'infinity', 'CPUWeight' => 0, 'IOWeight' => 300));
        $this->assertStringContainsAndOmitsStrings(array('<div class="h-metric-l">CPU and disk</div>', 'disk I/O priority 300'),
            array('CPU thread', 'CPU priority'), $html);
    }

    public function testNoCardWhenNothingIsConfigured(): void
    {
        foreach (array(null, array(), array('cpuQuotaPercent' => 0, 'CPUWeight' => 0, 'IOWeight' => 0),
            array('cpuQuotaPercent' => 'infinity', 'CPUWeight' => -5, 'IOWeight' => 'x')) as $config) {
            $this->assertStringNotContainsString('CPU and disk', $this->renderWelcome($config));
        }
    }
}
