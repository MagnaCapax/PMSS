<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/apps/packages/helpers.php';

class PackageQueueTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetPackageState();
    }

    private function resetPackageState(): void
    {
        $GLOBALS['PMSS_PACKAGE_QUEUE'] = [];
        $GLOBALS['PMSS_POST_INSTALL_COMMANDS'] = [];
        $GLOBALS['PMSS_PACKAGE_WARNINGS'] = [];
        $GLOBALS['PMSS_PACKAGE_ERRORS'] = [];
        putenv('PMSS_PACKAGE_INSTALL_WARNINGS');
        putenv('PMSS_PACKAGE_INSTALL_ERRORS');
    }

    private function writeBaseline(array $rows): string
    {
        return $this->pmssWriteTempFile('baseline', implode("\n", $rows)."\n");
    }

    public function testQueuePackagesAddsEntries(): void
    {
        pmssQueuePackages(['foo', 'bar']);
        $this->assertEquals(['foo', 'bar'], $GLOBALS['PMSS_PACKAGE_QUEUE'][PMSS_PACKAGE_QUEUE_DEFAULT] ?? []);
    }

    public function testQueuePackagesIgnoresDuplicates(): void
    {
        pmssQueuePackages(['foo']);
        pmssQueuePackages(['foo', 'bar']);
        $this->assertEquals(['foo', 'bar'], $GLOBALS['PMSS_PACKAGE_QUEUE'][PMSS_PACKAGE_QUEUE_DEFAULT] ?? []);
    }

    public function testQueuePackagesMaintainsOrder(): void
    {
        pmssQueuePackages(['a']);
        pmssQueuePackages(['c', 'b']);
        $this->assertEquals(['a', 'c', 'b'], $GLOBALS['PMSS_PACKAGE_QUEUE'][PMSS_PACKAGE_QUEUE_DEFAULT] ?? []);
    }

    public function testFlushPackageQueueClearsQueue(): void
    {
        pmssQueuePackages(['foo']);
        $this->pmssWithEnv(['PMSS_DRY_RUN' => '1'], static function (): void {
            pmssFlushPackageQueue();
        });
        $this->assertEquals([], $GLOBALS['PMSS_PACKAGE_QUEUE']);
    }

    public function testFlushPackageQueueSummarisesWarningsAndErrors(): void
    {
        $GLOBALS['PMSS_PACKAGE_QUEUE'] = [PMSS_PACKAGE_QUEUE_DEFAULT => []];
        $GLOBALS['PMSS_PACKAGE_WARNINGS'] = ['warn', 'warn', 'warn2'];
        $GLOBALS['PMSS_PACKAGE_ERRORS'] = ['err'];
        $this->pmssWithEnv(['PMSS_DRY_RUN' => '1'], static function (): void {
            pmssFlushPackageQueue();
        });
        $this->assertEquals([], $GLOBALS['PMSS_PACKAGE_WARNINGS']);
        $this->assertEquals([], $GLOBALS['PMSS_PACKAGE_ERRORS']);
        $this->assertEquals('2', getenv('PMSS_PACKAGE_INSTALL_WARNINGS') ?: '');
        $this->assertEquals('1', getenv('PMSS_PACKAGE_INSTALL_ERRORS') ?: '');
    }

    public function testReportPackageQueueBaselineDiffFindsMissingPackages(): void
    {
        pmssQueuePackages(['pkg-in-baseline', 'pkg-outside-baseline']);
        $baselinePath = $this->writeBaseline([
            'pkg-in-baseline install',
            'another-baseline-package install',
        ]);

        try {
            $summary = pmssReportPackageQueueBaselineDiff($baselinePath);
        } finally {
            @unlink($baselinePath);
        }

        $this->assertEquals(['pkg-outside-baseline'], $summary['queuedAbsentFromBaseline'] ?? []);
        $this->assertEquals(['pkg-outside-baseline'], $summary['queuedMissingOnHost'] ?? []);
        $this->assertEquals([], $summary['installedAbsentFromBaseline'] ?? []);
    }

    public function testReportPackageQueueBaselineDiffSupportsShortRows(): void
    {
        pmssQueuePackages(['pkg-short-form']);
        $baselinePath = $this->writeBaseline([
            'pkg-short-form',
            '# comment row',
        ]);

        try {
            $summary = pmssReportPackageQueueBaselineDiff($baselinePath);
        } finally {
            @unlink($baselinePath);
        }

        $this->assertEquals([], $summary['queuedAbsentFromBaseline'] ?? []);
        $this->assertEquals([], $summary['queuedMissingOnHost'] ?? []);
        $this->assertEquals([], $summary['installedAbsentFromBaseline'] ?? []);
    }

    public function testPackageQueueBaselineInstallSetSkipsNonInstallStates(): void
    {
        $baselinePath = $this->writeBaseline([
            'pkg-install install',
            'pkg-hold hold',
            'pkg-remove deinstall',
            'pkg-short-form',
        ]);

        try {
            $packages = pmssPackageQueueBaselineInstallSet($baselinePath);
        } finally {
            @unlink($baselinePath);
        }

        $this->assertEquals([
            'pkg-install' => true,
            'pkg-short-form' => true,
        ], $packages);
    }

    public function testInstallBestEffortSelectsFirstAvailableFallbackOnlyOnce(): void
    {
        if (!pmssPackageAvailable('bash')) {
            throw new SkipTest('bash package unavailable in local apt cache');
        }

        pmssInstallBestEffort([
            ['pmss-definitely-missing-package', 'bash', 'coreutils'],
            'bash',
            ['pmss-another-missing-package'],
        ], 'shell runtime');

        $this->assertEquals(['bash'], $GLOBALS['PMSS_PACKAGE_QUEUE'][PMSS_PACKAGE_QUEUE_DEFAULT] ?? []);
    }
}
