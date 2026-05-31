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
        $this->assertStringContainsString('nginx configuration test passed', $out);
        $this->assertStringContainsString('You should restart nginx', $out);
        $this->assertStringContainsString('nginx -t passed (rc=0)', $this->pmssReadFileOrEmpty($this->tempDir.'/logs/update.log'));
    }

    public function testConfigTestFailureReturnsErrorWithoutRestart(): void
    {
        $this->pmssWriteExecutableFile($this->tempDir.'/nginx-test-fail.sh', "#!/bin/sh\nprintf 'bad directive\\n'\nexit 1\n");

        list($rc, $out) = $this->runConfigTestWithCommands($this->tempDir.'/nginx-test-fail.sh', null, false);

        $this->assertEquals(1, $rc);
        $this->assertStringContainsString('FAILED', $out);
        $this->assertStringContainsString('Fix the configuration errors above before restarting nginx', $out);
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
        $this->assertStringContainsString('nginx restart', $out);
        $this->assertStringContainsString('FAILED', $out);
        $this->assertStringContainsString('CRITICAL: nginx restart failed (rc=4)', $this->pmssReadFileOrEmpty($this->tempDir.'/logs/update.log'));
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

    /** @return array{0:int,1:string} */
    private function runConfigTestWithCommands(string $testCommand, ?string $restartCommand, bool $restart): array
    {
        $captured = [1, ''];
        $this->pmssWithEnv([
            'PMSS_LOG_DIR' => $this->tempDir.'/logs',
            'PMSS_NGINX_CONFIG_TEST_COMMAND' => $testCommand,
            'PMSS_NGINX_RESTART_COMMAND' => $restartCommand,
        ], function () use (&$captured, $restart): void {
            $captured = $this->pmssCaptureStdout(function () use ($restart): int {
                return \pmssCreateNginxConfigTestAndMaybeRestart($restart);
            });
        });

        return $captured;
    }

    private function assertCommandEnvFallsBack(string $envKey, string $envValue, string $default): void
    {
        $this->pmssWithEnv([$envKey => $envValue], function () use ($envKey, $default): void {
            $this->assertEquals($default, \pmssCreateNginxConfigCommandFromEnv($envKey, $default));
        });
    }
}
