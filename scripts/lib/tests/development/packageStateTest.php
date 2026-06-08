<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class PackageStateTest extends TestCase
{
    public function testPackageStateModuleHasNoLegacyQueueSurface(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/packageState.php', [
            'function pmssPackageStatus(string $package): string',
            'function pmssPackageAvailable(string $package): bool',
        ]);
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/update/apps/deluge.php' => ['required' => ['pmssPackageStatus('], 'forbidden' => ['dpkg -s ', 'dpkg-query -W -f=']],
            'scripts/lib/update/distUpgrade/docker.php' => ['required' => ['pmssPackageStatus('], 'forbidden' => ['dpkg -s ', 'dpkg-query -W -f=']],
        ]);
        $this->pmssAssertRepoFileNotContainsStrings('scripts/lib/update/packageState.php', [
            'PMSS_PACKAGE'.'_QUEUE',
            'pmss'.'QueuePackages',
            'pmss'.'FlushPackageQueue',
            'pmss'.'InstallBestEffort',
        ], 'packageState.php must stay read-only: ');
    }

    public function testRetiredPackageQueueFilesStayRemoved(): void
    {
        $this->assertTrue(!is_file($this->pmssRepoPath('scripts/lib/update/apps/packages.php')), 'legacy package app must remain removed');
        $this->assertTrue(!is_file($this->pmssRepoPath('scripts/lib/update/apps/packages/helpers.php')), 'legacy package helpers must remain removed');
    }

    public function testUpdateStep2DoesNotCarryPackageQueueSkipPath(): void
    {
        $this->pmssAssertRepoFileNotContainsStrings('scripts/util/update-step2.php', [
            "'packages.php'",
            'PMSS_PACKAGE_INSTALL'.'_WARNINGS',
            'PMSS_PACKAGE_INSTALL'.'_ERRORS',
        ], 'retired package queue surface should not stay in update-step2: ');
        $this->pmssAssertRepoFileContainsString('scripts/util/update-step2.php', 'dpkg selections are the authoritative source of package');
    }
}
