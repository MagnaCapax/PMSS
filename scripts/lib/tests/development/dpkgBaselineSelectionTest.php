<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/environment.php';

class DpkgBaselineSelectionTest extends TestCase
{
    public function testSelectsAvailableValidatedBaselines(): void
    {
        foreach ([10, 12] as $version) {
            [$path, $logs] = $this->pmssArrayLoggerCapture(function (callable $logger) use ($version): string {
                return \pmssSelectDpkgSelectionsBaseline($version, $logger);
            });

            $this->assertTrue(is_string($path) && $path !== '', 'Expected a dpkg baseline path');
            $this->assertStringContainsString('selections-debian'.$version.'.txt', $path);
            $this->assertEquals([], $logs, 'Did not expect warnings when Debian '.$version.' baseline exists');
        }
    }

    public function testKeepsDebian13OnValidatedFallbackUntilPromotion(): void
    {
        [$path, $logs] = $this->pmssArrayLoggerCapture(function (callable $logger): string {
            return \pmssSelectDpkgSelectionsBaseline(13, $logger);
        });

        $this->assertTrue(is_string($path) && $path !== '', 'Expected a dpkg baseline path');

        $this->assertStringContainsString('selections-debian'.$this->latestValidatedBaselineMajor().'.txt', $path);
        $this->assertStringNotContainsString('selections-debian13.txt', $path);
        $this->pmssAssertMessagesContain(
            $logs,
            'Debian 13 dpkg baseline exists but is not validated for automatic use',
            'Expected warning about Debian 13 baseline remaining experimental'
        );
    }

    public function testBaselineServedToDebian13KeepsTheOpenvpnPackages(): void
    {
        // configureOpenvpn.php runs on every release and needs both packages. Debian 13 hosts get the
        // Debian 12 baseline until a captured Debian 13 file is promoted; the promoted file must keep them.
        [$path] = $this->pmssArrayLoggerCapture(function (callable $logger): string {
            return \pmssSelectDpkgSelectionsBaseline(13, $logger);
        });
        $installed = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $columns = preg_split('/\s+/', trim($line));
            if (count($columns) >= 2 && $columns[1] === 'install') {
                $installed[] = $columns[0];
            }
        }

        foreach (['openvpn', 'easy-rsa'] as $package) {
            $this->assertTrue(in_array($package, $installed, true), basename($path).' must select '.$package);
        }
    }

    public function testDebian13ValidationChecklistNamesTheOpenvpnChecks(): void
    {
        // The capture step is where a baseline can silently drop the packages, so the roadmap says to check them.
        $this->pmssAssertRepoFileContainsAllStrings('docs/dpkg-baseline.md', ['`openvpn` and `easy-rsa`', 'server-leaf renewal']);
    }

    public function testFutureMissingBaselineUsesLatestValidatedFallback(): void
    {
        [$path, $logs] = $this->pmssArrayLoggerCapture(function (callable $logger): string {
            return \pmssSelectDpkgSelectionsBaseline(99, $logger);
        });

        $this->assertTrue(is_string($path) && $path !== '', 'Expected a dpkg baseline path');
        $this->assertStringContainsString('selections-debian'.$this->latestValidatedBaselineMajor().'.txt', $path);
        $this->assertStringNotContainsString('selections-debian99.txt', $path);
        $this->pmssAssertMessagesContain(
            $logs,
            'Debian 99 dpkg baseline missing; using Debian '.$this->latestValidatedBaselineMajor().' baseline',
            'Expected warning about future distro fallback'
        );
    }

    public function testWarnsWhenRequestedBaselineIsUnavailable(): void
    {
        [$path, $logs] = $this->pmssArrayLoggerCapture(function (callable $logger): string {
            return \pmssSelectDpkgSelectionsBaseline(9, $logger);
        });

        $this->assertTrue(is_string($path) && $path !== '', 'Expected a dpkg baseline path');

        if (is_readable(dirname(__DIR__, 2).'/update/dpkg/selections-debian9.txt')) {
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
        $this->assertStringNotContainsString('selections-debian13.txt', $path);
        $this->assertEquals([], $logs, 'Did not expect warnings when distro version is unknown');
    }

    private function latestValidatedBaselineMajor(): int
    {
        $baselines = [];
        foreach (glob(dirname(__DIR__, 2).'/update/dpkg/selections-debian*.txt') ?: [] as $path) {
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
