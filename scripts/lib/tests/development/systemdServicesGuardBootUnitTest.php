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

        $commands = array_map(static function (array $entry): string {
            return (string) ($entry['command'] ?? '');
        }, $GLOBALS['PMSS_PROFILE'] ?? []);

        $joined = implode("\n", $commands);
        $this->assertTrue(strpos($joined, "install -m 0644 '".$template."' '/etc/systemd/system/pmss-systemd-services-guard.service'") !== false);
        $this->assertTrue(strpos($joined, 'systemctl daemon-reload') !== false);
        $this->assertTrue(strpos($joined, 'systemctl enable pmss-systemd-services-guard.service') !== false);
    }
}

