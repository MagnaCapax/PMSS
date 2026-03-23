<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/FilesystemCleanupTrait.php';
require_once dirname(__DIR__, 2).'/nginxConfig/configTest.php';

class NginxConfigTestTest extends TestCase
{
    use FilesystemCleanupTrait;

    /** @var string */
    private $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = $this->pmssMakeTempDir('pmss-nginx-config-test-', 0700);
        $this->pmssEnsureDir($this->tempDir.'/bin');
        $this->pmssEnsureDir($this->tempDir.'/logs');
    }

    public function testConfigTestPassesWithoutRestart(): void
    {
        $this->writeScript('nginx-test-success.sh', "#!/bin/sh\nprintf 'syntax ok\\n'\nexit 0\n");

        $this->pmssWithEnv([
            'PMSS_LOG_DIR' => $this->tempDir.'/logs',
            'PMSS_NGINX_CONFIG_TEST_COMMAND' => $this->tempDir.'/nginx-test-success.sh',
            'PMSS_NGINX_RESTART_COMMAND' => null,
        ], function (): void {
            ob_start();
            $rc = \pmssCreateNginxConfigTestAndMaybeRestart(false);
            $out = (string) ob_get_clean();

            $this->assertEquals(0, $rc);
            $this->assertStringContainsString('nginx configuration test passed', $out);
            $this->assertStringContainsString('You should restart nginx', $out);
            $this->assertStringContainsString('nginx -t passed (rc=0)', $this->readLog());
        });
    }

    public function testConfigTestFailureReturnsErrorWithoutRestart(): void
    {
        $this->writeScript('nginx-test-fail.sh', "#!/bin/sh\nprintf 'bad directive\\n'\nexit 1\n");

        $this->pmssWithEnv([
            'PMSS_LOG_DIR' => $this->tempDir.'/logs',
            'PMSS_NGINX_CONFIG_TEST_COMMAND' => $this->tempDir.'/nginx-test-fail.sh',
            'PMSS_NGINX_RESTART_COMMAND' => null,
        ], function (): void {
            ob_start();
            $rc = \pmssCreateNginxConfigTestAndMaybeRestart(false);
            $out = (string) ob_get_clean();

            $this->assertEquals(1, $rc);
            $this->assertStringContainsString('FAILED', $out);
            $this->assertStringContainsString('Fix the configuration errors above before restarting nginx', $out);
            $this->assertStringContainsString('CRITICAL: nginx -t failed (rc=1): bad directive', $this->readLog());
        });
    }

    public function testBrokenConfigBlocksRestartAttempt(): void
    {
        $this->writeScript('nginx-test-fail.sh', "#!/bin/sh\nprintf 'broken config\\n'\nexit 1\n");
        $restartLog = $this->tempDir.'/restart.log';
        $this->writeScript('restart.sh', "#!/bin/sh\nprintf 'restart\\n' >>".escapeshellarg($restartLog)."\nexit 0\n");

        $this->pmssWithEnv([
            'PMSS_LOG_DIR' => $this->tempDir.'/logs',
            'PMSS_NGINX_CONFIG_TEST_COMMAND' => $this->tempDir.'/nginx-test-fail.sh',
            'PMSS_NGINX_RESTART_COMMAND' => $this->tempDir.'/restart.sh',
        ], function () use ($restartLog): void {
            ob_start();
            $rc = \pmssCreateNginxConfigTestAndMaybeRestart(true);
            $out = (string) ob_get_clean();

            $this->assertEquals(1, $rc);
            $this->assertStringContainsString('Restart aborted: refusing to restart nginx with broken configuration', $out);
            $this->assertTrue(!file_exists($restartLog));
            $this->assertStringContainsString('restart aborted due to config test failure', $this->readLog());
        });
    }

    public function testRestartSuccessReturnsOk(): void
    {
        $this->writeScript('nginx-test-success.sh', "#!/bin/sh\nprintf 'syntax ok\\n'\nexit 0\n");
        $restartLog = $this->tempDir.'/restart.log';
        $this->writeScript('restart.sh', "#!/bin/sh\nprintf 'restart nginx\\n' >>".escapeshellarg($restartLog)."\nexit 0\n");

        $this->pmssWithEnv([
            'PMSS_LOG_DIR' => $this->tempDir.'/logs',
            'PMSS_NGINX_CONFIG_TEST_COMMAND' => $this->tempDir.'/nginx-test-success.sh',
            'PMSS_NGINX_RESTART_COMMAND' => $this->tempDir.'/restart.sh',
        ], function () use ($restartLog): void {
            ob_start();
            $rc = \pmssCreateNginxConfigTestAndMaybeRestart(true);
            $out = (string) ob_get_clean();

            $this->assertEquals(0, $rc);
            $this->assertStringContainsString('Done! nginx restarted', $out);
            $this->assertStringContainsString('restart nginx', (string) @file_get_contents($restartLog));
            $this->assertStringContainsString('nginx restarted', $this->readLog());
        });
    }

    public function testRestartFailureReturnsError(): void
    {
        $this->writeScript('nginx-test-success.sh', "#!/bin/sh\nprintf 'syntax ok\\n'\nexit 0\n");
        $this->writeScript('restart-fail.sh', "#!/bin/sh\nprintf 'restart failed\\n'\nexit 4\n");

        $this->pmssWithEnv([
            'PMSS_LOG_DIR' => $this->tempDir.'/logs',
            'PMSS_NGINX_CONFIG_TEST_COMMAND' => $this->tempDir.'/nginx-test-success.sh',
            'PMSS_NGINX_RESTART_COMMAND' => $this->tempDir.'/restart-fail.sh',
        ], function (): void {
            ob_start();
            $rc = \pmssCreateNginxConfigTestAndMaybeRestart(true);
            $out = (string) ob_get_clean();

            $this->assertEquals(1, $rc);
            $this->assertStringContainsString('nginx restart', $out);
            $this->assertStringContainsString('FAILED', $out);
            $this->assertStringContainsString('CRITICAL: nginx restart failed (rc=4)', $this->readLog());
        });
    }

    private function writeScript(string $name, string $body): void
    {
        $path = $this->tempDir.'/'.$name;
        $this->pmssWriteFile($path, $body);
        @chmod($path, 0755);
    }

    private function readLog(): string
    {
        return (string) @file_get_contents($this->tempDir.'/logs/update.log');
    }
}
