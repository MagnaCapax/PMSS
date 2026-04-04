<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class trafficLimitConsumerCharacterizationTest extends TestCase
{
    public function testCanonicalConsumersUseSharedTrafficLimitStateReader(): void
    {
        foreach (['scripts/cron/trafficLimits.php', 'scripts/lib/pmssStats.php', 'etc/skel/www/stats.php', 'etc/skel/www/welcome.php'] as $path) {
            $this->pmssAssertRepoFileContainsString($path, 'pmssTrafficLimitStateRead(');
        }
    }

    public function testWebConsumersNoLongerInlineTrafficLimitFileReads(): void
    {
        $this->pmssAssertStringNotContainsString("file_get_contents('../.trafficLimit')", $this->pmssReadRepoFile('etc/skel/www/stats.php'));
        $this->pmssAssertStringNotContainsString("file_get_contents('../.bonusTraffic')", $this->pmssReadRepoFile('etc/skel/www/stats.php'));
        $this->pmssAssertStringNotContainsString("file_get_contents('../.trafficLimit')", $this->pmssReadRepoFile('etc/skel/www/welcome.php'));
        $this->pmssAssertStringNotContainsString("pmssWelcomeInteger"."FileRead('../.bonusTraffic'", $this->pmssReadRepoFile('etc/skel/www/welcome.php'));
    }

    public function testPmssStatsNoLongerCarriesLocalIntegerReader(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/pmssStats.php');

        $this->pmssAssertStringNotContainsString('function '.'pmssStats'.'ReadIntegerFile(', $source);
        $this->assertStringContainsString("require_once __DIR__.'/user/trafficLimit.php';", $source);
    }

    public function testSerializedStateConsumersUseSharedArrayReader(): void
    {
        foreach (['scripts/lib/pmssStats.php', 'etc/skel/www/stats.php', 'etc/skel/www/welcome.php'] as $path) {
            $this->pmssAssertRepoFileContainsString($path, 'pmssTrafficReadSerializedArrayFile(');
        }
    }

    public function testLegacyWebConsumersNoLongerInlineUnserializeFileReads(): void
    {
        $statsSource = $this->pmssReadRepoFile('etc/skel/www/stats.php');
        $welcomeSource = $this->pmssReadRepoFile('etc/skel/www/welcome.php');

        $this->pmssAssertStringNotContainsString('@unserialize(@file_get_contents(', $statsSource);
        $this->pmssAssertStringNotContainsString('@unserialize(trim(@file_get_contents(', $welcomeSource);
        $this->pmssAssertStringNotContainsString('function readUserResourceData() {'.PHP_EOL.'    $resourcePath = \'../.resourceData\';', $welcomeSource);
    }
}
