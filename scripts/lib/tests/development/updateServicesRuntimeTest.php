<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/runtime/commands.php';
require_once dirname(__DIR__, 2).'/update/runtime/profile.php';
require_once dirname(__DIR__, 2).'/update/services/bootstrap.php';

class UpdateServicesRuntimeTest extends TestCase
{
    public function testSshdLegacyParserNormalizationKeepsLegacyFallbackContract(): void
    {
        $normalized = \pmssSshdLegacyParserTemplateNormalize("Port 22\nCiphers +aes128-ctr\nHostKeyAlgorithms ssh-ed25519");

        $this->assertStringContainsString("Port 22\n", $normalized);
        $this->assertSame(1, substr_count($normalized, "\nCiphers aes128-gcm@openssh.com"));
        $this->assertSame(1, substr_count($normalized, "\nKexAlgorithms curve25519-sha256@libssh.org"));
        $this->assertSame(1, substr_count($normalized, "\nMACs hmac-sha2-512-etm@openssh.com"));
        $this->assertStringContainsString('# HostKeyAlgorithms ssh-ed25519', $normalized);
    }

    public function testApplyRuntimeTemplatesLogsCommandsInStableOrderDuringDryRun(): void
    {
        $this->pmssResetRuntimeProfile();
        putenv('PMSS_DRY_RUN=1');

        try {
            \pmssApplyRuntimeTemplates();
        } finally {
            putenv('PMSS_DRY_RUN');
        }

        $commands = $this->pmssProfileCommands();

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
            'sshd -t',
            '/usr/bin/systemctl restart sshd',
        ], $commands);
    }
}
