<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/packageState.php';

class PackageStateTest extends TestCase
{
    public function testPackageStateModuleHasNoLegacyQueueSurface(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/packageState.php', [
            'function pmssPackageStatus(string $package): string',
            'function pmssPackageAvailable(string $package): bool',
        ], [
            'PMSS_PACKAGE'.'_QUEUE',
            'pmss'.'QueuePackages',
            'pmss'.'FlushPackageQueue',
            'pmss'.'InstallBestEffort',
        ]);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/apps/deluge.php',
            ['pmssPackageStatus('], ['dpkg -s ', 'dpkg-query -W -f=']);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/distUpgrade/docker.php',
            ['pmssPackageStatus('], ['dpkg -s ', 'dpkg-query -W -f=']);
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
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/util/update-step2.php', [
            '$pmssDockerPackageVersionsBefore = pmssDockerPackageVersions();',
            '$pmssDockerPackageVersionsAfter = pmssDockerPackageVersions();',
            'pmssDockerPackageVersionsChanged(',
            'PMSS_DOCKER_PACKAGES_CHANGED=',
        ], 'Missing Docker package change signal step: ', 'Docker package signal order changed at: ');
    }

    public function testUserMaintenanceUsesRestartWhenDockerPackagesChanged(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/users/docker.php', ["getenv('PMSS_DOCKER_PACKAGES_CHANGED') === '1' ? 'restart' : 'start'"]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/userMaintenance.php', ["getenv('PMSS_DOCKER_PACKAGES_CHANGED') === '1'"]);
    }

    public function testUpdateStep2DoesNotCarryPackageQueueSkipPath(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/util/update-step2.php',
            ['dpkg selections are the authoritative source of package'], [
                "'packages.php'",
                'PMSS_PACKAGE_INSTALL'.'_WARNINGS',
                'PMSS_PACKAGE_INSTALL'.'_ERRORS',
            ]);
    }
}
