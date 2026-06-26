<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/bootstrap.php';

class UpdateServicesRuntimeTest extends TestCase
{
    public function testSshdLegacyParserNormalizationKeepsLegacyFallbackContract(): void
    {
        $normalized = \pmssSshdLegacyParserTemplateNormalize("Port 22\nCiphers +aes128-ctr\nHostKeyAlgorithms ssh-ed25519");

        $this->assertStringContainsAllStrings(["Port 22\n", '# HostKeyAlgorithms ssh-ed25519'], $normalized);
        foreach (["\nCiphers aes128-gcm@openssh.com", "\nKexAlgorithms curve25519-sha256@libssh.org", "\nMACs hmac-sha2-512-etm@openssh.com"] as $directive) {
            $this->assertSame(1, substr_count($normalized, $directive));
        }
        $this->assertStringNotContainsString('hmac-ripemd160', $normalized);
    }

    public function testSshdLegacyParserNormalizationWarnsWhenCommentingSecurityDirectives(): void
    {
        $messages = [];
        \pmssSshdLegacyParserTemplateNormalize(
            "HostKeyAlgorithms +ssh-rsa\nPubkeyAcceptedKeyTypes +ssh-rsa\n",
            $this->pmssMakeArrayLogger($messages)
        );

        foreach (['HostKeyAlgorithms +ssh-rsa', 'PubkeyAcceptedKeyTypes +ssh-rsa'] as $message) {
            $this->pmssAssertMessagesContain($messages, $message);
        }
    }

    public function testSshdValidationCommandUsesAbsolutePathAndFallbacks(): void
    {
        $sshd = $this->pmssMakeTempDir('pmss-sshd-bin-').'/sshd';
        $this->pmssWriteExecutableFile($sshd, "#!/bin/sh\nexit 0\n");

        foreach ([
            [$sshd, \pmssBuildCommand($sshd, ['-t'])],
            [$this->pmssMakeTempPath('pmss-missing-sshd-'), 'sshd -t'],
        ] as [$path, $expected]) {
            $this->assertSame($expected, \pmssSshdValidationCommand($path));
        }
    }

    public function testSshdValidationRc127SkipsLegacyFallbackMutation(): void
    {
        $this->pmssResetRuntimeProfile();
        $sshdConfig = $this->pmssMakeTempDir('pmss-sshd-config-').'/sshd_config';
        $original = "Port 22\nCiphers +aes128-ctr\nHostKeyAlgorithms +ssh-rsa\nPubkeyAcceptedKeyTypes +ssh-rsa\n";
        $this->pmssWriteFile($sshdConfig, $original);

        $result = \pmssSshdValidateConfigWithLegacyFallback($sshdConfig, '/bin/sh -c '.escapeshellarg('exit 127'));

        $this->assertFalse($result);
        $this->assertSame($original, $this->pmssReadFileOrEmpty($sshdConfig));
        $this->assertEquals(['/bin/sh -c '.escapeshellarg('exit 127')], $this->pmssProfileCommands());
    }

    public function testApplyRuntimeTemplatesLogsCommandsInStableOrderDuringDryRun(): void
    {
        $this->pmssResetRuntimeProfile();
        $this->pmssWithEnv(['PMSS_DRY_RUN' => '1'], function (): void {
            \pmssApplyRuntimeTemplates();
        });

        $commands = $this->pmssProfileCommands();

        $this->assertEquals([
            'cp /etc/seedbox/config/template.rc.local /etc/rc.local',
            'chown root:root /etc/rc.local',
            'chmod 750 /etc/rc.local',
            'nohup /etc/rc.local >> /dev/null 2>&1',
            'cp /etc/seedbox/config/template.systemd.system.conf /etc/systemd/system.conf',
            'chmod 644 /etc/systemd/system.conf',
            '/usr/bin/systemctl daemon-reexec',
            "install -d -m 0755 '/etc/systemd/system/ssh.service.d'",
            "cp '/etc/seedbox/config/template.ssh.service.pmss-starvation.conf' '/etc/systemd/system/ssh.service.d/10-pmss-starvation-resistance.conf'",
            '/usr/bin/systemctl daemon-reload || true',
            'cp /etc/seedbox/config/template.sshd_config /etc/ssh/sshd_config',
            'chmod 644 /etc/ssh/sshd_config',
            \pmssSshdValidationCommand(),
            '/usr/bin/systemctl restart sshd',
        ], $commands);
    }

    public function testCronRestartDropinCreatesDirectoryAndFileSafely(): void
    {
        list($root, $dropinDir, $dropinFile) = $this->pmssCronDropinFixture('pmss-cron-dropin-');
        $changed = false;

        $this->assertTrue(\pmssEnsureCronRestartDropin($dropinDir, $dropinFile, $root, $changed));
        $this->assertTrue($changed);
        $this->assertSame(\pmssCronRestartDropinContent(), $this->pmssReadFileOrEmpty($dropinFile));
        $this->assertSame(0644, fileperms($dropinFile) & 0777);
    }

    public function testCronRestartDropinBoundsAggregateCronTasks(): void
    {
        $content = \pmssCronRestartDropinContent();

        $this->assertTrue(strpos($content, "[Service]\n") === 0);
        $this->assertStringContainsAllStrings(["TasksAccounting=yes\n", "TasksMax=8192\n", "Restart=always\n"], $content);
    }

    public function testSshdStarvationDropinTemplateDocumentsDefenseInDepth(): void
    {
        $content = $this->pmssReadRepoFile('etc/seedbox/config/template.ssh.service.pmss-starvation.conf');

        $this->assertStringContainsAllStrings([
            "[Service]\n",
            'per-user slice containment prevents pid exhaustion',
            "CPUWeight=10000\n",
            "IOWeight=10000\n",
            "OOMScoreAdjust=-1000\n",
            "MemoryMin=64M\n",
        ], $content);
    }

    public function testCronRestartDropinSkipsUnchangedContent(): void
    {
        list($root, $dropinDir, $dropinFile) = $this->pmssCronDropinFixture('pmss-cron-dropin-same-');
        $this->pmssWriteFile($dropinFile, \pmssCronRestartDropinContent());
        $mtime = filemtime($dropinFile);
        $changed = true;

        $this->assertTrue(\pmssEnsureCronRestartDropin($dropinDir, $dropinFile, $root, $changed));
        $this->assertFalse($changed);
        $this->assertSame($mtime, filemtime($dropinFile));
    }

    public function testCronRestartDropinRefusesSymlinkTarget(): void
    {
        list($root, $dropinDir, $dropinFile) = $this->pmssCronDropinFixture('pmss-cron-dropin-link-');
        $outside = $root.'/outside.conf';
        $this->pmssWriteFile($outside, 'original');
        $this->pmssEnsureDir($dropinDir);
        symlink($outside, $dropinFile);
        $changed = true;

        $this->assertFalse(\pmssEnsureCronRestartDropin($dropinDir, $dropinFile, $root, $changed));
        $this->assertFalse($changed);
        $this->assertSame('original', $this->pmssReadFileOrEmpty($outside));
    }

    public function testCronRestartDropinRefusesSymlinkedDirectory(): void
    {
        list($root, , $dropinFile) = $this->pmssCronDropinFixture('pmss-cron-dropin-dirlink-');
        $realDir = $root.'/real';
        $linkDir = dirname($dropinFile);
        $this->pmssEnsureDir($realDir);
        symlink($realDir, $linkDir);
        $changed = true;

        $this->assertFalse(\pmssEnsureCronRestartDropin($linkDir, $linkDir.'/pmss-restart.conf', $root, $changed));
        $this->assertFalse($changed);
        $this->assertFalse(file_exists($realDir.'/pmss-restart.conf'));
    }

    public function testCronRestartDropinRefusesSymlinkedAncestor(): void
    {
        $root = $this->pmssMakeTempDir('pmss-cron-dropin-ancestor-link-');
        $realRoot = $root.'/real';
        $linkRoot = $root.'/link';
        $this->pmssEnsureDir($realRoot.'/system');
        $this->pmssCreateSymlinkOrSkip($realRoot, $linkRoot);
        $dropinDir = $linkRoot.'/system/cron.service.d';
        $changed = true;

        $this->assertFalse(\pmssEnsureCronRestartDropin($dropinDir, $dropinDir.'/pmss-restart.conf', $root, $changed));
        $this->assertFalse($changed);
        $this->assertFalse(file_exists($realRoot.'/system/cron.service.d/pmss-restart.conf'));
    }

    public function testCronRestartDropinRequiresTargetInsideDirectory(): void
    {
        list($root, $dropinDir) = $this->pmssCronDropinFixture('pmss-cron-dropin-outside-');
        $outside = $root.'/outside.conf';
        $changed = true;

        $this->assertFalse(\pmssEnsureCronRestartDropin($dropinDir, $outside, $root, $changed));
        $this->assertFalse($changed);
        $this->assertFalse(file_exists($outside));
    }

    private function pmssCronDropinFixture(string $prefix): array
    {
        $root = $this->pmssMakeTempDir($prefix);
        $dropinDir = $root.'/cron.service.d';
        return [$root, $dropinDir, $dropinDir.'/pmss-restart.conf'];
    }
}
