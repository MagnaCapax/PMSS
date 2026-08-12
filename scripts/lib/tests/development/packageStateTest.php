<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/packageState.php';

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

    public function testDockerPackageVersionDeltaTracksRuntimePackagesOnly(): void
    {
        $before = [
            'containerd.io' => '1',
            'docker-ce' => '1',
            'docker-ce-rootless-extras' => '1',
        ];
        $this->assertFalse(\pmssDockerPackageVersionsChanged($before, $before));

        $containerdChanged = $before;
        $containerdChanged['containerd.io'] = '2';
        $this->assertTrue(\pmssDockerPackageVersionsChanged($before, $containerdChanged));

        $dockerChanged = $before;
        $dockerChanged['docker-ce'] = '2';
        $this->assertTrue(\pmssDockerPackageVersionsChanged($before, $dockerChanged));

        $extrasChanged = $before;
        $extrasChanged['docker-ce-rootless-extras'] = '2';
        $this->assertTrue(\pmssDockerPackageVersionsChanged($before, $extrasChanged));

        $cliOnlyChanged = $before;
        $cliOnlyChanged['docker-ce-cli'] = '2';
        $this->assertFalse(\pmssDockerPackageVersionsChanged($before, $cliOnlyChanged));
    }

    public function testUpdateStep2PublishesDockerPackageChangeSignal(): void
    {
        $src = (string) file_get_contents($this->pmssRepoPath('scripts/util/update-step2.php'));
        $this->assertOrderedStrings([
            '$pmssDockerPackageVersionsBefore = pmssDockerPackageVersions();',
            '$pmssDockerPackageVersionsAfter = pmssDockerPackageVersions();',
            'pmssDockerPackageVersionsChanged(',
            'PMSS_DOCKER_PACKAGES_CHANGED=',
        ], $src, 'Missing Docker package change signal step: ', 'Docker package signal order changed at: ');
    }

    public function testUserMaintenanceUsesRestartWhenDockerPackagesChanged(): void
    {
        $dockerSrc = (string) file_get_contents($this->pmssRepoPath('scripts/lib/update/users/docker.php'));
        $maintenanceSrc = (string) file_get_contents($this->pmssRepoPath('scripts/lib/update/userMaintenance.php'));
        $this->assertStringContainsString("getenv('PMSS_DOCKER_PACKAGES_CHANGED') === '1' ? 'restart' : 'start'", $dockerSrc);
        $this->assertStringContainsString("getenv('PMSS_DOCKER_PACKAGES_CHANGED') === '1'", $maintenanceSrc);
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
