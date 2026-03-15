<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/runtime/commands.php';
require_once dirname(__DIR__, 2).'/update/runtime/profile.php';
require_once dirname(__DIR__, 2).'/update/services/bootstrap.php';

class UpdateServicesRuntimeTest extends TestCase
{
    private function reset(): void
    {
        unset($GLOBALS['PMSS_PROFILE'], $GLOBALS['PMSS_LAST_COMMAND_OUTPUT']);
    }

    public function testApplyRuntimeTemplatesLogsCommandsInStableOrderDuringDryRun(): void
    {
        $this->reset();
        putenv('PMSS_DRY_RUN=1');

        try {
            \pmssApplyRuntimeTemplates();
        } finally {
            putenv('PMSS_DRY_RUN');
        }

        $commands = array_map(static function (array $entry): string {
            return (string) ($entry['command'] ?? '');
        }, $GLOBALS['PMSS_PROFILE'] ?? []);

        $this->assertEquals([
            'cp /etc/seedbox/config/template.rc.local /etc/rc.local',
            'chown root:root /etc/rc.local',
            'chmod 750 /etc/rc.local',
            'nohup /etc/rc.local >> /dev/null 2>&1',
            'cp /etc/seedbox/config/template.systemd.system.conf /etc/systemd/system.conf',
            'chmod 644 /etc/systemd/system.conf',
            '/usr/bin/systemctl daemon-reexec',
            'cp /etc/seedbox/config/template.sshd_config /etc/ssh/sshd_config',
            'chmod 644 /etc/ssh/sshd_config',
            '/usr/bin/systemctl restart sshd',
        ], $commands);
    }
}
