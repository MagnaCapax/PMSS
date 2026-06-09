<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class PackageStateTest extends TestCase
{
    public function testPackageStateModuleHasNoLegacyQueueSurface(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/update/packageState.php' => [
                'required' => [
                    'function pmssPackageStatus(string $package): string',
                    'function pmssPackageAvailable(string $package): bool',
                ],
                'forbidden' => [
                    'PMSS_PACKAGE'.'_QUEUE',
                    'pmss'.'QueuePackages',
                    'pmss'.'FlushPackageQueue',
                    'pmss'.'InstallBestEffort',
                ],
            ],
            'scripts/lib/update/apps/deluge.php' => ['required' => ['pmssPackageStatus('], 'forbidden' => ['dpkg -s ', 'dpkg-query -W -f=']],
            'scripts/lib/update/distUpgrade/docker.php' => ['required' => ['pmssPackageStatus('], 'forbidden' => ['dpkg -s ', 'dpkg-query -W -f=']],
        ]);
    }

    public function testRetiredPackageQueueFilesStayRemoved(): void
    {
        $this->assertTrue(!is_file($this->pmssRepoPath('scripts/lib/update/apps/packages.php')), 'legacy package app must remain removed');
        $this->assertTrue(!is_file($this->pmssRepoPath('scripts/lib/update/apps/packages/helpers.php')), 'legacy package helpers must remain removed');
    }

    public function testUpdateStep2DoesNotCarryPackageQueueSkipPath(): void
    {
        $this->pmssAssertRepoFileContract('scripts/util/update-step2.php', [
                'required' => ['dpkg selections are the authoritative source of package'],
                'forbidden' => [
                    "'packages.php'",
                    'PMSS_PACKAGE_INSTALL'.'_WARNINGS',
                    'PMSS_PACKAGE_INSTALL'.'_ERRORS',
                ],
            ]);
    }
}
