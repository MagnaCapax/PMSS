<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/environment.php';

class DpkgBaselineSelectionTest extends TestCase
{
    public function testSelectsDebian10BaselineWhenAvailable(): void
    {
        [$path, $logs] = $this->pmssArrayLoggerCapture(function (callable $logger): string {
            return \pmssSelectDpkgSelectionsBaseline(10, $logger);
        });

        $this->assertTrue(is_string($path) && $path !== '', 'Expected a dpkg baseline path');
        $this->assertStringContainsString('selections-debian10.txt', $path);
        $this->assertEquals([], $logs, 'Did not expect warnings when Debian 10 baseline exists');
    }

    public function testSelectsDebian12BaselineWhenAvailable(): void
    {
        [$path, $logs] = $this->pmssArrayLoggerCapture(function (callable $logger): string {
            return \pmssSelectDpkgSelectionsBaseline(12, $logger);
        });

        $this->assertTrue(is_string($path) && $path !== '', 'Expected a dpkg baseline path');
        $this->assertStringContainsString('selections-debian12.txt', $path);
        $this->assertEquals([], $logs, 'Did not expect warnings when Debian 12 baseline exists');
    }

    public function testKeepsDebian13OnValidatedFallbackUntilPromotion(): void
    {
        [$path, $logs] = $this->pmssArrayLoggerCapture(function (callable $logger): string {
            return \pmssSelectDpkgSelectionsBaseline(13, $logger);
        });

        $this->assertTrue(is_string($path) && $path !== '', 'Expected a dpkg baseline path');

        $this->assertStringContainsString('selections-debian'.$this->latestValidatedBaselineMajor().'.txt', $path);
        $this->pmssAssertMessagesContain(
            $logs,
            'Debian 13 dpkg baseline exists but is not validated for automatic use',
            'Expected warning about Debian 13 baseline remaining experimental'
        );
    }

    public function testWarnsWhenRequestedBaselineIsUnavailable(): void
    {
        [$path, $logs] = $this->pmssArrayLoggerCapture(function (callable $logger): string {
            return \pmssSelectDpkgSelectionsBaseline(9, $logger);
        });

        $this->assertTrue(is_string($path) && $path !== '', 'Expected a dpkg baseline path');

        if (is_readable($this->baselinePath(9))) {
            $this->assertStringContainsString('selections-debian9.txt', $path);
            $this->assertEquals([], $logs, 'Did not expect warnings when Debian 9 baseline exists');
            return;
        }

        $this->assertStringContainsString('selections-debian'.$this->latestValidatedBaselineMajor().'.txt', $path);
        $this->pmssAssertMessagesContain($logs, 'Debian 9 dpkg baseline', 'Expected warning about unavailable Debian 9 baseline');
    }

    public function testSelectsLatestValidatedBaselineWhenVersionUnknown(): void
    {
        [$path, $logs] = $this->pmssArrayLoggerCapture(function (callable $logger): string {
            return \pmssSelectDpkgSelectionsBaseline(null, $logger);
        });

        $this->assertTrue(is_string($path) && $path !== '', 'Expected a dpkg baseline path');
        $this->assertStringContainsString('selections-debian'.$this->latestValidatedBaselineMajor().'.txt', $path);
        $this->assertEquals([], $logs, 'Did not expect warnings when distro version is unknown');
    }

    private function baselinePath(int $major): string
    {
        return $this->dpkgDir().'/selections-debian'.$major.'.txt';
    }

    private function dpkgDir(): string
    {
        return dirname(__DIR__, 2).'/update/dpkg';
    }

    private function latestValidatedBaselineMajor(): int
    {
        $baselines = [];
        foreach (glob($this->dpkgDir().'/selections-debian*.txt') ?: [] as $path) {
            if (preg_match('/selections-debian([0-9]+)\\.txt$/', $path, $match)) {
                $major = (int) $match[1];
                if ($major <= 12) {
                    $baselines[] = $major;
                }
            }
        }

        return $baselines ? max($baselines) : 0;
    }
}
