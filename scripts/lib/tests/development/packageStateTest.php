<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/packageState.php';

class PackageStateTest extends TestCase
{
    public function testPackageStateModuleHasNoLegacyQueueSurface(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/packageState.php');

        $this->assertStringContainsString('function pmssPackageStatus(string $package): string', $src);
        $this->assertStringContainsString('function pmssPackageAvailable(string $package): bool', $src);
        foreach ([
            'PMSS_PACKAGE'.'_QUEUE',
            'pmss'.'QueuePackages',
            'pmss'.'FlushPackageQueue',
            'pmss'.'InstallBestEffort',
        ] as $needle) {
            $this->pmssAssertStringNotContainsString($needle, $src, 'packageState.php must stay read-only');
        }
    }

    public function testRetiredPackageQueueFilesStayRemoved(): void
    {
        $root = dirname(__DIR__, 4);

        $this->assertTrue(!is_file($root.'/scripts/lib/update/apps/packages.php'), 'legacy package app must remain removed');
        $this->assertTrue(!is_file($root.'/scripts/lib/update/apps/packages/helpers.php'), 'legacy package helpers must remain removed');
    }

    public function testUpdateStep2DoesNotCarryPackageQueueSkipPath(): void
    {
        $src = $this->pmssReadRepoFile('scripts/util/update-step2.php');

        $this->pmssAssertStringNotContainsString("'packages.php'", $src, 'removed package app should not be in the app loader skip list');
        $this->pmssAssertStringNotContainsString('PMSS_PACKAGE_INSTALL'.'_WARNINGS', $src, 'retired package counters should not stay in update-step2');
        $this->pmssAssertStringNotContainsString('PMSS_PACKAGE_INSTALL'.'_ERRORS', $src, 'retired package counters should not stay in update-step2');
        $this->assertStringContainsString('dpkg selections are the authoritative source of package', $src);
    }
}
