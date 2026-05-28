<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateStep2SystemServiceHardeningWiringTest extends TestCase
{
    public function testUpdateStep2WiresSystemdHardeningHelpers(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/util/update-step2.php', "require_once __DIR__.'/../lib/update/services/systemd.php';");
        $this->pmssAssertRepoFileSubstringCountAtLeast(
            'scripts/util/update-step2.php',
            'pmssStopDisableMaskSeedboxSystemServices',
            2,
            'Expected system service hardening to run at least twice (pre/post installers)'
        );
        $this->pmssAssertRepoFileContainsString('scripts/util/update-step2.php', 'pmssEnsureSystemdServicesGuardBootUnit', 'Expected boot-time systemd guard unit installation to be wired');
        $this->pmssAssertRepoFileSubstringCountAtLeast(
            'scripts/util/update-step2.php',
            'pmssStopDisableMaskSystemdUnit',
            1,
            'Expected Apache hardening helper to be invoked'
        );
    }
}
