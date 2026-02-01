<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/environment.php';

class DpkgBaselineSelectionTest extends TestCase
{
    public function testSelectsDebian10BaselineWhenAvailable(): void
    {
        $logs = [];
        $logger = function (string $message) use (&$logs): void {
            $logs[] = $message;
        };

        $path = \pmssSelectDpkgSelectionsBaseline(10, $logger);
        $this->assertTrue(is_string($path) && $path !== '', 'Expected a dpkg baseline path');
        $this->assertStringContainsString('selections-debian10.txt', $path);
        $this->assertEquals([], $logs, 'Did not expect warnings when Debian 10 baseline exists');
    }

    public function testSelectsDebian12BaselineWhenAvailable(): void
    {
        $logs = [];
        $logger = function (string $message) use (&$logs): void {
            $logs[] = $message;
        };

        $path = \pmssSelectDpkgSelectionsBaseline(12, $logger);
        $this->assertTrue(is_string($path) && $path !== '', 'Expected a dpkg baseline path');
        $this->assertStringContainsString('selections-debian12.txt', $path);
        $this->assertEquals([], $logs, 'Did not expect warnings when Debian 12 baseline exists');
    }

    public function testSelectsDebian13BaselineOrWarnsAndFallsBack(): void
    {
        $logs = [];
        $logger = function (string $message) use (&$logs): void {
            $logs[] = $message;
        };

        $path = \pmssSelectDpkgSelectionsBaseline(13, $logger);
        $this->assertTrue(is_string($path) && $path !== '', 'Expected a dpkg baseline path');

        if (is_readable($this->baselinePath(13))) {
            $this->assertStringContainsString('selections-debian13.txt', $path);
            $this->assertEquals([], $logs, 'Did not expect warnings when Debian 13 baseline exists');
            return;
        }

        $this->assertStringContainsString('selections-debian'.$this->latestBaselineMajor().'.txt', $path);
        $this->assertTrue((bool) array_filter($logs, static function (string $line): bool {
            return strpos($line, 'Debian 13 dpkg baseline missing') !== false;
        }), 'Expected warning about missing Debian 13 baseline');
    }

    public function testWarnsWhenRequestedBaselineIsUnavailable(): void
    {
        $logs = [];
        $logger = function (string $message) use (&$logs): void {
            $logs[] = $message;
        };

        $path = \pmssSelectDpkgSelectionsBaseline(9, $logger);
        $this->assertTrue(is_string($path) && $path !== '', 'Expected a dpkg baseline path');

        if (is_readable($this->baselinePath(9))) {
            $this->assertStringContainsString('selections-debian9.txt', $path);
            $this->assertEquals([], $logs, 'Did not expect warnings when Debian 9 baseline exists');
            return;
        }

        $this->assertStringContainsString('selections-debian'.$this->latestBaselineMajor().'.txt', $path);
        $this->assertTrue((bool) array_filter($logs, static function (string $line): bool {
            return strpos($line, 'Debian 9 dpkg baseline') !== false;
        }), 'Expected warning about unavailable Debian 9 baseline');
    }

    public function testSelectsLatestBaselineWhenVersionUnknown(): void
    {
        $logs = [];
        $logger = function (string $message) use (&$logs): void {
            $logs[] = $message;
        };

        $path = \pmssSelectDpkgSelectionsBaseline(null, $logger);
        $this->assertTrue(is_string($path) && $path !== '', 'Expected a dpkg baseline path');
        $this->assertStringContainsString('selections-debian'.$this->latestBaselineMajor().'.txt', $path);
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

    private function latestBaselineMajor(): int
    {
        $baselines = [];
        foreach (glob($this->dpkgDir().'/selections-debian*.txt') ?: [] as $path) {
            if (preg_match('/selections-debian([0-9]+)\\.txt$/', $path, $match)) {
                $baselines[] = (int) $match[1];
            }
        }

        return $baselines ? max($baselines) : 0;
    }
}

