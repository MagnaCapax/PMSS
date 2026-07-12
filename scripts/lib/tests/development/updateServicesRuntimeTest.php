<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/bootstrap.php';

class UpdateServicesRuntimeTest extends TestCase
{
    public function testSshdTemplateSetsRecoveryQueueHardeningWithoutBreakingLegacyClients(): void
    {
        $config = "\n".$this->pmssReadRepoFile('etc/seedbox/config/template.sshd_config');

        $this->assertStringContainsAllStrings([
            "\nLoginGraceTime 30s\n",
            "\nMaxStartups 60:30:200\n",
            "\nCiphers +aes128-cbc,aes192-cbc,aes256-cbc,chacha20-poly1305@openssh.com\n",
            "\nKexAlgorithms +diffie-hellman-group-exchange-sha1,diffie-hellman-group14-sha1,diffie-hellman-group1-sha1\n",
            "\nMACs +hmac-sha1\n",
            "\nPrintMotd no\n",
        ], $config);
    }

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
        $messages = $this->pmssArrayLoggerMessages(function (callable $logger): void {
            \pmssSshdLegacyParserTemplateNormalize("HostKeyAlgorithms +ssh-rsa\nPubkeyAcceptedKeyTypes +ssh-rsa\n", $logger);
        });

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

    public function testCronRestartDropinReservesCpuHeadroomForSystemSlice(): void
    {
        // 4 threads -> cron capped at 3 threads (300%), reserving 1 thread for sshd/root recovery.
        $this->assertStringContainsAllStrings(["CPUAccounting=yes\n", "CPUQuota=300%\n"], \pmssCronRestartDropinContent(4));

        // 8 threads -> 700%, still reserving exactly one thread.
        $this->assertTrue(strpos(\pmssCronRestartDropinContent(8), "CPUQuota=700%\n") !== false);

        // 2 threads -> 100% (1 reserved, 1 for cron).
        $this->assertTrue(strpos(\pmssCronRestartDropinContent(2), "CPUQuota=100%\n") !== false);

        // 1 thread -> nothing to reserve; no CPUQuota cap (never throttle a single-core host).
        $this->assertTrue(strpos(\pmssCronRestartDropinContent(1), 'CPUQuota=') === false);
    }

    public function testCronPamSystemdSessionContainsUserCronInUserSlice(): void
    {
        $this->assertSame("session    optional   pam_systemd.so\n", \pmssCronPamSystemdLine());

        $f = tempnam(sys_get_temp_dir(), 'pmss-cron-pam-');
        try {
            file_put_contents($f, "@include common-auth\nsession    required     pam_loginuid.so\n");

            // Appends pam_systemd to an existing regular cron PAM file.
            $this->assertTrue(\pmssEnsureCronPamSystemdSession($f));
            $this->assertTrue(strpos(file_get_contents($f), 'pam_systemd.so') !== false);
            // Original lines preserved.
            $this->assertTrue(strpos(file_get_contents($f), 'pam_loginuid.so') !== false);

            // Idempotent: a second call does not duplicate the line.
            $this->assertTrue(\pmssEnsureCronPamSystemdSession($f));
            $this->assertSame(1, substr_count(file_get_contents($f), 'pam_systemd.so'));
        } finally {
            @unlink($f);
        }

        // A commented pam_systemd line does NOT count as present (real line is appended).
        $g = tempnam(sys_get_temp_dir(), 'pmss-cron-pam2-');
        try {
            file_put_contents($g, "# session optional pam_systemd.so (disabled)\n@include common-auth\n");
            $this->assertTrue(\pmssEnsureCronPamSystemdSession($g));
            $this->assertSame(2, substr_count(file_get_contents($g), 'pam_systemd.so'));
        } finally {
            @unlink($g);
        }

        // Absent cron PAM file -> no-op success, file is NOT created.
        $absent = sys_get_temp_dir().'/pmss-cron-pam-absent-'.getmypid();
        @unlink($absent);
        $this->assertTrue(\pmssEnsureCronPamSystemdSession($absent));
        $this->assertFalse(file_exists($absent));
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
