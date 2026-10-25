<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/nginxConfig/configTest.php';

class NginxConfigTestTest extends TestCase
{
    protected function setUp(): void
    {
        $this->tempDir = $this->pmssMakeTempDir('pmss-nginx-config-test-', 0700);
        $this->pmssEnsureDir($this->tempDir.'/bin');
        $this->pmssEnsureDir($this->tempDir.'/logs');
    }

    public function testConfigTestPassesWithoutRestart(): void
    {
        $this->pmssWriteExecutableFile($this->tempDir.'/nginx-test-success.sh', "#!/bin/sh\nprintf 'syntax ok\\n'\nexit 0\n");

        list($rc, $out) = $this->runConfigTestWithCommands($this->tempDir.'/nginx-test-success.sh', null, false);

        $this->assertEquals(0, $rc);
        $this->assertStringContainsAllStrings(['nginx configuration test passed', 'You should restart nginx'], $out);
        $this->assertStringContainsString('nginx -t passed (rc=0)', $this->pmssReadFileOrEmpty($this->tempDir.'/logs/update.log'));
    }

    public function testConfigTestFailureReturnsErrorWithoutRestart(): void
    {
        $this->pmssWriteExecutableFile($this->tempDir.'/nginx-test-fail.sh', "#!/bin/sh\nprintf 'bad directive\\n'\nexit 1\n");

        list($rc, $out) = $this->runConfigTestWithCommands($this->tempDir.'/nginx-test-fail.sh', null, false);

        $this->assertEquals(1, $rc);
        $this->assertStringContainsAllStrings(['FAILED', 'Fix the configuration errors above before restarting nginx'], $out);
        $this->assertStringContainsString('CRITICAL: nginx -t failed (rc=1): bad directive', $this->pmssReadFileOrEmpty($this->tempDir.'/logs/update.log'));
    }

    public function testBrokenConfigBlocksRestartAttempt(): void
    {
        $this->pmssWriteExecutableFile($this->tempDir.'/nginx-test-fail.sh', "#!/bin/sh\nprintf 'broken config\\n'\nexit 1\n");
        $restartLog = $this->tempDir.'/restart.log';
        $this->pmssWriteExecutableFile($this->tempDir.'/restart.sh', "#!/bin/sh\nprintf 'restart\\n' >>".escapeshellarg($restartLog)."\nexit 0\n");

        list($rc, $out) = $this->runConfigTestWithCommands($this->tempDir.'/nginx-test-fail.sh', $this->tempDir.'/restart.sh', true);

        $this->assertEquals(1, $rc);
        $this->assertStringContainsString('Restart aborted: refusing to restart nginx with broken configuration', $out);
        $this->assertTrue(!file_exists($restartLog));
        $this->assertStringContainsString('restart aborted due to config test failure', $this->pmssReadFileOrEmpty($this->tempDir.'/logs/update.log'));
    }

    public function testBrokenConfigRestartOutputSequenceStaysStable(): void
    {
        $this->pmssWriteExecutableFile($this->tempDir.'/nginx-test-fail.sh', "#!/bin/sh\nprintf 'broken config\\n'\nexit 1\n");
        $this->pmssWriteExecutableFile($this->tempDir.'/restart.sh', "#!/bin/sh\nexit 0\n");

        list($rc, $out) = $this->runConfigTestWithCommands($this->tempDir.'/nginx-test-fail.sh', $this->tempDir.'/restart.sh', true);

        $this->assertEquals(1, $rc);
        $this->assertEquals(
            '[CRITICAL] nginx configuration test FAILED (rc=1)'.PHP_EOL
            .'broken config'.PHP_EOL
            .'## Restart aborted: refusing to restart nginx with broken configuration'.PHP_EOL
            .'## Fix the errors above, then manually restart:'.PHP_EOL
            .'   systemctl restart nginx'.PHP_EOL,
            $out
        );
    }

    public function testRestartSuccessReturnsOk(): void
    {
        $this->pmssWriteExecutableFile($this->tempDir.'/nginx-test-success.sh', "#!/bin/sh\nprintf 'syntax ok\\n'\nexit 0\n");
        $restartLog = $this->tempDir.'/restart.log';
        $this->pmssWriteExecutableFile($this->tempDir.'/restart.sh', "#!/bin/sh\nprintf 'restart nginx\\n' >>".escapeshellarg($restartLog)."\nexit 0\n");

        list($rc, $out) = $this->runConfigTestWithCommands($this->tempDir.'/nginx-test-success.sh', $this->tempDir.'/restart.sh', true);

        $this->assertEquals(0, $rc);
        $this->assertStringContainsString('Done! nginx restarted', $out);
        $this->assertStringContainsString('restart nginx', (string) @file_get_contents($restartLog));
        $this->assertStringContainsString('nginx restarted', $this->pmssReadFileOrEmpty($this->tempDir.'/logs/update.log'));
    }

    public function testRestartFailureReturnsError(): void
    {
        $this->pmssWriteExecutableFile($this->tempDir.'/nginx-test-success.sh', "#!/bin/sh\nprintf 'syntax ok\\n'\nexit 0\n");
        $this->pmssWriteExecutableFile($this->tempDir.'/restart-fail.sh', "#!/bin/sh\nprintf 'restart failed\\n'\nexit 4\n");

        list($rc, $out) = $this->runConfigTestWithCommands($this->tempDir.'/nginx-test-success.sh', $this->tempDir.'/restart-fail.sh', true);

        $this->assertEquals(1, $rc);
        $this->assertStringContainsAllStrings(['nginx restart', 'FAILED'], $out);
        $this->assertStringContainsString('CRITICAL: nginx restart failed (rc=4)', $this->pmssReadFileOrEmpty($this->tempDir.'/logs/update.log'));
    }

    public function testReloadSuccessReturnsOk(): void
    {
        $this->pmssWriteExecutableFile($this->tempDir.'/nginx-test-success.sh', "#!/bin/sh\nexit 0\n");
        $reloadLog = $this->tempDir.'/reload.log';
        $this->pmssWriteExecutableFile($this->tempDir.'/reload.sh', "#!/bin/sh\nprintf 'reload nginx\\n' >>".escapeshellarg($reloadLog)."\nexit 0\n");

        list($rc, $out) = $this->runConfigTestWithCommands($this->tempDir.'/nginx-test-success.sh', null, false, $this->tempDir.'/reload.sh', true);

        $this->assertEquals(0, $rc);
        $this->assertStringContainsString('nginx reloaded or started', $out);
        $this->assertStringContainsString('reload nginx', $this->pmssReadFileOrEmpty($reloadLog));
    }

    public function testBrokenConfigBlocksReload(): void
    {
        $this->pmssWriteExecutableFile($this->tempDir.'/nginx-test-fail.sh', "#!/bin/sh\nexit 1\n");
        $reloadLog = $this->tempDir.'/reload.log';
        $this->pmssWriteExecutableFile($this->tempDir.'/reload.sh', "#!/bin/sh\nprintf 'called\\n' >>".escapeshellarg($reloadLog)."\n");

        list($rc, $out) = $this->runConfigTestWithCommands($this->tempDir.'/nginx-test-fail.sh', null, false, $this->tempDir.'/reload.sh', true);

        $this->assertEquals(1, $rc);
        $this->assertStringContainsString('Reload aborted', $out);
        $this->assertTrue(!file_exists($reloadLog));
    }

    public function testReloadFailureReturnsError(): void
    {
        $this->pmssWriteExecutableFile($this->tempDir.'/nginx-test-success.sh', "#!/bin/sh\nexit 0\n");
        $this->pmssWriteExecutableFile($this->tempDir.'/reload-fail.sh', "#!/bin/sh\nexit 4\n");

        list($rc, $out) = $this->runConfigTestWithCommands($this->tempDir.'/nginx-test-success.sh', null, false, $this->tempDir.'/reload-fail.sh', true);

        $this->assertEquals(1, $rc);
        $this->assertStringContainsString('nginx reload/start FAILED', $out);
    }

    public function testWhitespaceOnlyConfigTestCommandFallsBackToDefault(): void
    {
        $this->assertCommandEnvFallsBack('PMSS_NGINX_CONFIG_TEST_COMMAND', " \t ", 'nginx -t 2>&1');
    }

    public function testMultilineConfigTestCommandFallsBackToDefault(): void
    {
        $this->assertCommandEnvFallsBack('PMSS_NGINX_CONFIG_TEST_COMMAND', "nginx -t\nprintf 'surprise'\n", 'nginx -t 2>&1');
    }

    public function testWhitespaceOnlyRestartCommandFallsBackToDefault(): void
    {
        $this->assertCommandEnvFallsBack('PMSS_NGINX_RESTART_COMMAND', " \n ", 'systemctl restart nginx || /etc/init.d/nginx restart');
    }

    public function testMultilineRestartCommandFallsBackToDefault(): void
    {
        $this->assertCommandEnvFallsBack('PMSS_NGINX_RESTART_COMMAND', "systemctl restart nginx\nprintf 'surprise'\n", 'systemctl restart nginx || /etc/init.d/nginx restart');
    }

    public function testCommandOverridesRejectLineBreaksBeforeTrimming(): void
    {
        foreach (['PMSS_NGINX_CONFIG_TEST_COMMAND', 'PMSS_NGINX_RESTART_COMMAND', 'PMSS_NGINX_RELOAD_COMMAND'] as $envKey) {
            foreach (["\n", "\r", "\r\n"] as $lineBreak) {
                // Edge line breaks must follow the same refusal path as embedded ones.
                foreach ([$lineBreak.'custom-command', 'custom-command'.$lineBreak, 'custom'.$lineBreak.'command', " \t".$lineBreak."custom-command \t"] as $value) {
                    $this->assertCommandEnvFallsBack($envKey, $value, 'fallback-command');
                }
            }
        }
    }

    public function testCommandOverridesPreserveValidShellBytesAndWhitespaceNormalization(): void
    {
        $cases = [
            ['custom-command', 'custom-command'],
            [" \tcustom-command\t ", 'custom-command'],
            ['first || second', 'first || second'],
            ['first && second', 'first && second'],
            ["printf 'two words'", "printf 'two words'"],
            ['0', '0'],
        ];
        foreach (['PMSS_NGINX_CONFIG_TEST_COMMAND', 'PMSS_NGINX_RESTART_COMMAND', 'PMSS_NGINX_RELOAD_COMMAND'] as $envKey) {
            foreach ($cases as [$value, $expected]) {
                $this->pmssWithEnv([$envKey => $value], function () use ($envKey, $expected): void {
                    $this->assertEquals($expected, \pmssCreateNginxConfigCommandFromEnv($envKey, 'fallback-command'));
                });
            }
            foreach (['', " \t "] as $value) {
                $this->assertCommandEnvFallsBack($envKey, $value, 'fallback-command');
            }
            $this->pmssWithEnv([$envKey => null], function () use ($envKey): void {
                $this->assertEquals('fallback-command', \pmssCreateNginxConfigCommandFromEnv($envKey, 'fallback-command'));
            });
        }
    }

    /** @return array{0:int,1:string} */
    private function runConfigTestWithCommands(string $testCommand, ?string $restartCommand, bool $restart, ?string $reloadCommand = null, bool $reload = false): array
    {
        return $this->pmssCaptureStdout(function () use ($restart, $reload): int {
            return \pmssCreateNginxConfigTestAndMaybeRestart($restart, $reload);
        }, [
            'PMSS_LOG_DIR' => $this->tempDir.'/logs',
            'PMSS_NGINX_CONFIG_TEST_COMMAND' => $testCommand,
            'PMSS_NGINX_RESTART_COMMAND' => $restartCommand,
            'PMSS_NGINX_RELOAD_COMMAND' => $reloadCommand,
        ]);
    }

    private function assertCommandEnvFallsBack(string $envKey, string $envValue, string $default): void
    {
        $this->pmssWithEnv([$envKey => $envValue], function () use ($envKey, $default): void {
            $this->assertEquals($default, \pmssCreateNginxConfigCommandFromEnv($envKey, $default));
        });
    }
}
