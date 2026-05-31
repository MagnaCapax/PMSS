<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/runtime/commands.php';
require_once dirname(__DIR__, 2).'/update/runtime/profile.php';
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

    public function testStopDisableMaskSystemdUnitKeepsStopDisableMaskOrderInDryRun(): void
    {
        $this->pmssResetRuntimeProfile();

        $this->pmssWithEnv(['PMSS_DRY_RUN' => '1'], function (): void {
            pmssStopDisableMaskSystemdUnit('demo.service', 'Demo', true);
        });

        $this->assertEquals([
            "systemctl stop 'demo.service' || true",
            "systemctl disable 'demo.service' || true",
            "systemctl mask 'demo.service' || true",
        ], $this->pmssProfileCommands());
    }

    public function testStopDisableMaskSystemdUnitOmitsMaskWhenDisabled(): void
    {
        $this->pmssResetRuntimeProfile();

        $this->pmssWithEnv(['PMSS_DRY_RUN' => '1'], function (): void {
            pmssStopDisableMaskSystemdUnit('demo.service', 'Demo', false);
        });

        $this->assertEquals([
            "systemctl stop 'demo.service' || true",
            "systemctl disable 'demo.service' || true",
        ], $this->pmssProfileCommands());
    }
}
