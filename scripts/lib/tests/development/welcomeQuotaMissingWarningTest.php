<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class welcomeQuotaMissingWarningTest extends TestCase
{
    private function loadWelcomeGaugeFunctions(): void
    {
        if (function_exists('createGauge')) {
            return;
        }

        $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');
        $start = strpos($source, 'function createStackedGauge');
        $this->assertTrue($start !== false, 'welcome.php gauge helpers should remain present');

        $tail = substr($source, $start);
        $tail = preg_replace('/\?>\s*$/', '', $tail);
        $fixture = $this->pmssMakeTempPath('pmss-welcome-gauge-', '.php');
        file_put_contents($fixture, "<?php\n".$tail);
        require_once $fixture;
    }

    public function testQuotaMissingWarningGuardUsesOnlyQuotaLimitFields(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');

        $this->assertStringContainsString('if ($hardLimit == 0 || $totalSpace == 0)', $source);
        $this->pmssAssertStringNotContainsString('$freeSpace == 0', $source);
        $this->pmssAssertStringNotContainsString('|| $usedBytes == 0', $source);
    }

    public function testWelcomePageLabelsDelugeWebUiPasswordClearly(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');

        $this->assertStringContainsString('Deluge Web UI password:', $source);
        $this->pmssAssertStringNotContainsString('Deluge password: <b>', $source);
    }

    public function testWelcomePageUsesCustomerDelugePasswordRotationHelper(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');

        $this->assertStringContainsString('pmssDelugeServicePasswordRotate((string) $username)', $source);
        $this->pmssAssertStringNotContainsString('pmssDelugeAuthWriteLocalclientPassword($delugeAuthPath, $newDelugePassword)', $source);
    }

    public function testWelcomeGaugeHtmlAndColorSnapshot(): void
    {
        $this->loadWelcomeGaugeFunctions();

        $this->assertSame(
            array(
                'single' => '85016bc86aa6155a5775e00ffba371c7663ba1270101e66c012b34dbdadfed9e',
                'stacked' => 'e0d82eedbaa584ada4e7a80b626b3adef78dbb415f090899de45ecb662f18293',
                'colors' => array('-10' => '90ee99', '0' => '99e699', '50' => 'c4bf99', '100' => 'ee9999', '101' => 'FF4040'),
            ),
            array(
                'single' => hash('sha256', $this->createGauge('Used / Limit', 'Used / Limit<br />Bonus', 75, 90)),
                'stacked' => hash('sha256', $this->createStackedGauge(
                    'Process: 1 | Cache: 2',
                    'Process: 1 | Cache: 2',
                    66.7,
                    array(
                        array('width' => 33.3, 'color' => '#aabbcc'),
                        array('width' => 22.2, 'color' => '#ddeeff'),
                        array('width' => 44.5, 'color' => 'transparent'),
                    )
                )),
                'colors' => array('-10' => $this->gaugeColor(-10), '0' => $this->gaugeColor(0), '50' => $this->gaugeColor(50), '100' => $this->gaugeColor(100), '101' => $this->gaugeColor(101)),
            )
        );
    }

    private function createGauge($titleText, $footerText, $percent, $percentMax = 0): string
    {
        /** @var callable(mixed,mixed,mixed,mixed): string $builder */
        $builder = 'createGauge';
        return $builder($titleText, $footerText, $percent, $percentMax);
    }

    private function createStackedGauge($titleText, $footerText, $percent, array $segments): string
    {
        /** @var callable(mixed,mixed,mixed,array<int,array<string,mixed>>): string $builder */
        $builder = 'createStackedGauge';
        return $builder($titleText, $footerText, $percent, $segments);
    }

    private function gaugeColor($percent): string
    {
        /** @var callable(mixed): string $color */
        $color = 'gaugeColor';
        return $color($percent);
    }
}
