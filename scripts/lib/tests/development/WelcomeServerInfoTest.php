<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** The Welcome status strip's Server card: host uptime only, the host-wide load average is gone (ADR 0147). */
final class WelcomeServerInfoTest extends TestCase
{
    /**
     * Render the Server card once per uptime path in a child process. Everything in welcome.php
     * from pmssWelcomeTrafficUpgradeUrlBuild() on is function definitions only, so the tail loads
     * without running the page.
     *
     * @return array<int,string>
     */
    private function renderServerMetrics(array $uptimePaths): array
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');
        $start = strpos($source, 'function pmssWelcomeTrafficUpgradeUrlBuild');
        $this->assertTrue($start !== false, 'welcome.php view helpers should remain present');

        $fixture = $this->pmssWriteFile(
            $this->pmssMakeTempPath('pmss-welcome-server-info-', '.php'),
            "<?php\nrequire_once ".var_export($this->pmssRepoPath('etc/skel/www/scriptsInc.php'), true).";\n"
            .preg_replace('/\?>\s*$/', '', substr($source, $start))
        );
        $output = $this->pmssRunInlinePhpRequire(
            $fixture,
            '$html = array(); foreach ('.var_export(array_values($uptimePaths), true).' as $path) { $html[] = pmssWelcomeHomeServerMetricHtmlBuild($path); }'
            .'echo json_encode($html);',
            [],
            '2>&1'
        );
        $this->pmssAssertStringNotContainsString('Undefined', $output);
        $this->pmssAssertStringNotContainsString('Warning', $output);
        return $this->pmssDecodeJsonArray($output);
    }

    private function writeUptimeFile(string $uptime): string
    {
        return $this->pmssWriteFile($this->pmssMakeTempDir('pmss-welcome-server-info-data-').'/uptime', $uptime);
    }

    public function testValidUptimeRendersSharedServerLabel(): void
    {
        list($html) = $this->renderServerMetrics(array($this->writeUptimeFile("90061.25 1.00\n")));

        $this->assertStringContainsAllStrings(array(
            '<div class="h-metric-l">Server</div>',
            '<div class="h-metric-v">1 day 1 hour</div>',
            'uptime · shared server',
        ), $html);
    }

    public function testSubMinuteUptimeDoesNotRoundUp(): void
    {
        list($html) = $this->renderServerMetrics(array($this->writeUptimeFile("59.99 10\n")));

        $this->assertStringContainsString('<div class="h-metric-v">under 1 minute</div>', $html);
    }

    public function testMissingUptimeFailsSoft(): void
    {
        list($html) = $this->renderServerMetrics(array($this->writeUptimeFile("1 1\n").'.missing'));

        $this->assertStringContainsAllStrings(array('<div class="h-metric-v">—</div>', 'uptime unavailable'), $html);
        $this->pmssAssertStringNotContainsString('shared server', $html);
    }

    public function testInvalidUptimeInputsFailSoft(): void
    {
        $paths = array();
        foreach (array('', '-1 1', 'nope 1', 'INF 1', 'NAN 1') as $uptime) {
            $paths[] = $this->writeUptimeFile($uptime);
        }

        foreach ($this->renderServerMetrics($paths) as $index => $html) {
            $this->assertStringContainsString('uptime unavailable', $html, 'uptime case '.$index);
        }
    }

    public function testSymlinkedUptimeFileIsRejected(): void
    {
        $target = $this->writeUptimeFile("3600 1\n");
        $link = $target.'.link';
        $this->pmssCreateSymlinkOrSkip($target, $link);

        list($linked, $direct) = $this->renderServerMetrics(array($link, $target));
        $this->assertStringContainsString('uptime unavailable', $linked);
        $this->assertStringContainsString('<div class="h-metric-v">1 hour</div>', $direct);
    }

    public function testServerCardDropsHostLoadAverage(): void
    {
        list($html) = $this->renderServerMetrics(array($this->writeUptimeFile("3600 1\n")));

        $this->pmssAssertStringNotContainsString('load', strtolower($html));
        $this->pmssAssertRepoFileContainsAndOmitsStrings('etc/skel/www/welcome.php', array(), array(
            '/proc/loadavg',
            'Server load average',
            'function pmssWelcomeServerInfoHtmlBuild(',
        ));
    }

    public function testWelcomeRendersServerCardAfterMemoryCard(): void
    {
        $this->assertOrderedStrings(array(
            '.pmssWelcomeHomeMemoryMetricHtmlBuild($homeMemoryState)',
            '.pmssWelcomeHomeServerMetricHtmlBuild();',
        ), $this->pmssReadRepoFile('etc/skel/www/welcome.php'));
    }
}
