<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/runtime/commands.php';
require_once dirname(__DIR__, 2).'/update/runtime/profile.php';
require_once dirname(__DIR__, 2).'/update/services/systemd.php';

class SystemdServicesGuardBootUnitTest extends TestCase
{
    private function reset(): void
    {
        unset($GLOBALS['PMSS_PROFILE'], $GLOBALS['PMSS_LAST_COMMAND_OUTPUT']);
    }

    private function profileCommands(): array
    {
        return array_map(static function (array $entry): string {
            return (string) ($entry['command'] ?? '');
        }, $GLOBALS['PMSS_PROFILE'] ?? []);
    }

    public function testBootUnitInstallIsLoggedInDryRun(): void
    {
        $this->reset();

        $tmp = sys_get_temp_dir().'/pmss-systemd-boot-unit-'.bin2hex(random_bytes(4));
        @mkdir($tmp, 0755, true);
        $template = $tmp.'/template.systemd.pmss-systemd-services-guard.service';
        @file_put_contents($template, "[Unit]\nDescription=test\n");

        putenv('PMSS_CONFIG_DIR='.$tmp);
        putenv('PMSS_DRY_RUN=1');

        pmssEnsureSystemdServicesGuardBootUnit();

        putenv('PMSS_DRY_RUN');
        putenv('PMSS_CONFIG_DIR');

        $this->assertEquals([
            "install -m 0644 '".$template."' '/etc/systemd/system/pmss-systemd-services-guard.service'",
            'systemctl daemon-reload || true',
            'systemctl enable pmss-systemd-services-guard.service || true',
        ], $this->profileCommands());
    }

    public function testStopDisableMaskSystemdUnitKeepsStopDisableMaskOrderInDryRun(): void
    {
        $this->reset();
        putenv('PMSS_DRY_RUN=1');

        try {
            pmssStopDisableMaskSystemdUnit('demo.service', 'Demo', true);
        } finally {
            putenv('PMSS_DRY_RUN');
        }

        $this->assertEquals([
            "systemctl stop 'demo.service' || true",
            "systemctl disable 'demo.service' || true",
            "systemctl mask 'demo.service' || true",
        ], $this->profileCommands());
    }

    public function testStopDisableMaskSystemdUnitOmitsMaskWhenDisabled(): void
    {
        $this->reset();
        putenv('PMSS_DRY_RUN=1');

        try {
            pmssStopDisableMaskSystemdUnit('demo.service', 'Demo', false);
        } finally {
            putenv('PMSS_DRY_RUN');
        }

        $this->assertEquals([
            "systemctl stop 'demo.service' || true",
            "systemctl disable 'demo.service' || true",
        ], $this->profileCommands());
    }
}
