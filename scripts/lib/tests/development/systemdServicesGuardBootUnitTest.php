<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/systemd.php';

class SystemdServicesGuardBootUnitTest extends TestCase
{
    public function testBootUnitInstallIsLoggedInDryRun(): void
    {
        $this->pmssResetRuntimeProfile();

        $tmp = $this->pmssMakeTempDir('pmss-systemd-boot-unit-');
        $template = $this->pmssWriteFile($tmp.'/template.systemd.pmss-systemd-services-guard.service', "[Unit]\nDescription=test\n");

        $this->pmssWithEnv(['PMSS_CONFIG_DIR' => $tmp, 'PMSS_DRY_RUN' => '1'], function (): void {
            pmssEnsureSystemdServicesGuardBootUnit();
        });

        $this->assertEquals([
            "install -m 0644 '".$template."' '/etc/systemd/system/pmss-systemd-services-guard.service'",
            'systemctl daemon-reload || true',
            'systemctl enable pmss-systemd-services-guard.service || true',
        ], $this->pmssProfileCommands());
    }

    public function testStopDisableMaskSystemdUnitHonorsMaskFlagInDryRun(): void
    {
        foreach ([true, false] as $mask) {
            $this->pmssResetRuntimeProfile();

            $this->pmssWithEnv(['PMSS_DRY_RUN' => '1'], function () use ($mask): void {
                pmssStopDisableMaskSystemdUnit('demo.service', 'Demo', $mask);
            });

            $expected = [
                "systemctl stop 'demo.service' || true",
                "systemctl disable 'demo.service' || true",
            ];
            if ($mask) {
                $expected[] = "systemctl mask 'demo.service' || true";
            }

            $this->assertEquals($expected, $this->pmssProfileCommands());
        }
    }

    public function testStopDisableMaskSystemdUnitUsesSharedFailSoftActionPath(): void
    {
        $this->pmssResetRuntimeProfile();

        $this->pmssWithEnv(['PMSS_DRY_RUN' => '1'], function (): void {
            pmssStopDisableMaskSystemdUnit('demo.service', 'Demo', false);
        });

        $viaPolicy = $this->pmssProfileCommands();
        $this->pmssResetRuntimeProfile();

        $this->pmssWithEnv(['PMSS_DRY_RUN' => '1'], function (): void {
            pmssSystemdUnitActionIfPresent('demo.service', 'Stopping Demo system service', 'stop', true);
            pmssSystemdUnitActionIfPresent('demo.service', 'Disabling Demo system service', 'disable', true);
        });

        $this->assertEquals($this->pmssProfileCommands(), $viaPolicy);
    }
}
