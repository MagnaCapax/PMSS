<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateStep2SystemServiceHardeningWiringTest extends TestCase
{
    public function testUpdateStep2WiresSystemdHardeningHelpers(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/util/update-step2.php', "'update/services/systemd.php'");
        $source = $this->pmssReadRepoFile('scripts/util/update-step2.php').$this->pmssReadRepoFile('scripts/lib/update/packagePhase.php');
        $this->assertTrue(substr_count($source, 'pmssStopDisableMaskSeedboxSystemServices') >= 2,
            'Expected system service hardening to run at least twice (pre/post installers)');
        $this->pmssAssertRepoFileContainsString('scripts/lib/update/packagePhase.php', 'pmssEnsureSystemdServicesGuardBootUnit', 'Expected boot-time systemd guard unit installation to be wired');
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/util/update-step2.php', [
            'pmssUpdateStep2PackagePhase($distribution, $pmssDockerPackageVersionsBefore);',
            "\$apps = glob('/scripts/lib/update/apps/*.php')",
            "pmssRunProfiledCallable('Applying system service disable/mask policy (post-app)', 'pmssStopDisableMaskSeedboxSystemServices');",
        ], 'Missing service policy phase: ', 'Service policy phase order changed at: ');
        $this->pmssAssertRepoFileSubstringCountAtLeast(
            'scripts/lib/update/packagePhase.php',
            'pmssStopDisableMaskSystemdUnit',
            1,
            'Expected Apache hardening helper to be invoked'
        );
    }
}
