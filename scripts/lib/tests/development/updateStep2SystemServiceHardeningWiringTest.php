<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateStep2SystemServiceHardeningWiringTest extends TestCase
{
    public function testUpdateStep2WiresSystemdHardeningHelpers(): void
    {
        $src = $this->pmssReadRepoFile('scripts/util/update-step2.php');

        $this->assertTrue(strpos($src, "require_once __DIR__.'/../lib/update/services/systemd.php';") !== false);

        $this->assertTrue(
            substr_count($src, 'pmssStopDisableMaskSeedboxSystemServices') >= 2,
            'Expected system service hardening to run at least twice (pre/post installers)'
        );
        $this->assertTrue(
            strpos($src, 'pmssEnsureSystemdServicesGuardBootUnit') !== false,
            'Expected boot-time systemd guard unit installation to be wired'
        );
        $this->assertTrue(
            substr_count($src, 'pmssStopDisableMaskSystemdUnit') >= 1,
            'Expected Apache hardening helper to be invoked'
        );
    }
}
