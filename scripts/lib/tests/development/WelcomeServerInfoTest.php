<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class WelcomeServerInfoTest extends TestCase
{
    /** Load only the customer-tree helpers exercised by these hermetic tests. */
    private function loadServerInfoHelpers(): void
    {
        if (function_exists('pmssWelcomeServerInfoHtmlBuild')) return;

        $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');
        $start = strpos($source, 'function pmssWelcomeMetricSectionHtmlBuild');
        $end = strpos($source, 'function pmssWelcomePercent');
        $this->assertTrue($start !== false && $end !== false && $end > $start, 'Welcome server-info helpers should remain grouped');

        $fixture = $this->pmssMakeTempPath('pmss-welcome-server-info-', '.php');
        $scriptsHelper = $this->pmssRepoPath('etc/skel/www/scriptsInc.php');
        $helpers = str_replace('pmssWelcomeHtmlAttr($title)', 'pmssCustomerHtmlAttr($title)', substr($source, $start, $end - $start));
        require_once $this->pmssWriteFile(
            $fixture,
            "<?php\nrequire_once ".var_export($scriptsHelper, true).";\n"
            .$helpers
        );
    }

    /** Write uptime/load fixtures and return their paths. */
    private function writeServerInfoFiles(string $uptime, string $load): array
    {
        $dir = $this->pmssMakeTempDir('pmss-welcome-server-info-data-');
        return array(
            $this->pmssWriteFile($dir.'/uptime', $uptime),
            $this->pmssWriteFile($dir.'/loadavg', $load),
        );
    }

    public function testValidMetricsRenderSharedServerLabels(): void
    {
        $this->loadServerInfoHelpers();
        list($uptimePath, $loadPath) = $this->writeServerInfoFiles("90061.25 1.00\n", "1.25 2.5 3.75 2/100 123\n");

        $html = \pmssWelcomeServerInfoHtmlBuild($uptimePath, $loadPath);
        $this->assertStringContainsAllStrings(array(
            '<h6>Server Info</h6>',
            'Server uptime: 1 day 1 hour',
            'Server load average (1 / 5 / 15 min, shared server): 1.25 / 2.50 / 3.75',
        ), $html);
    }

    public function testSubMinuteUptimeDoesNotRoundUp(): void
    {
        $this->loadServerInfoHelpers();
        list($uptimePath, $loadPath) = $this->writeServerInfoFiles("59.99 10\n", "0 0 0 1/1 1\n");

        $this->assertStringContainsString('Server uptime: under 1 minute', \pmssWelcomeServerInfoHtmlBuild($uptimePath, $loadPath));
    }

    public function testMissingUptimePreservesValidLoad(): void
    {
        $this->loadServerInfoHelpers();
        list($unusedUptimePath, $loadPath) = $this->writeServerInfoFiles("1 1\n", "0.1 0.2 0.3 1/1 1\n");

        $html = \pmssWelcomeServerInfoHtmlBuild($unusedUptimePath.'.missing', $loadPath);
        $this->assertStringContainsAllStrings(array('Server uptime: unavailable', '0.10 / 0.20 / 0.30'), $html);
    }

    public function testMalformedLoadInputsFailSoft(): void
    {
        $this->loadServerInfoHelpers();
        foreach (array('', '1 2', '1 nope 3', '1 -2 3', 'INF 2 3') as $load) {
            list($uptimePath, $loadPath) = $this->writeServerInfoFiles("3600 1\n", $load);
            $html = \pmssWelcomeServerInfoHtmlBuild($uptimePath, $loadPath);
            $this->assertStringContainsAllStrings(array('Server uptime: 1 hour', 'shared server): unavailable'), $html);
        }
    }

    public function testInvalidUptimeInputsFailSoft(): void
    {
        $this->loadServerInfoHelpers();
        foreach (array('', '-1 1', 'nope 1', 'INF 1', 'NAN 1') as $uptime) {
            list($uptimePath, $loadPath) = $this->writeServerInfoFiles($uptime, "1 2 3 1/1 1\n");
            $html = \pmssWelcomeServerInfoHtmlBuild($uptimePath, $loadPath);
            $this->assertStringContainsAllStrings(array('Server uptime: unavailable', '1.00 / 2.00 / 3.00'), $html);
        }
    }

    public function testSymlinkedMetricFilesAreRejected(): void
    {
        $this->loadServerInfoHelpers();
        list($uptimeTarget, $loadTarget) = $this->writeServerInfoFiles("3600 1\n", "1 2 3 1/1 1\n");
        $uptimeLink = $uptimeTarget.'.link';
        $loadLink = $loadTarget.'.link';
        $this->pmssCreateSymlinkOrSkip($uptimeTarget, $uptimeLink);
        $this->pmssCreateSymlinkOrSkip($loadTarget, $loadLink);

        $html = \pmssWelcomeServerInfoHtmlBuild($uptimeLink, $loadLink);
        $this->assertStringContainsAllStrings(array('Server uptime: unavailable', 'shared server): unavailable'), $html);
    }

    public function testWelcomeRendersServerInfoAfterRamInfo(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');
        $this->assertOrderedStrings(array(
            'echo pmssWelcomeMemorySectionHtmlBuild();',
            'echo pmssWelcomeServerInfoHtmlBuild();',
        ), $source);
    }
}
