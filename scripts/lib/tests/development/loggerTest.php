<?php
namespace PMSS\Tests;

// Tests for scripts/lib/logger.php: Logger and logmsg wrapper
require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/logger.php';

class LoggerTest extends TestCase
{
    public function testLoggerWritesToCustomDir(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-logs-', 0700);
        $logger = new \Logger(__FILE__, $dir);
        $logger->msg('hello custom');
        $base = basename(__FILE__, '.php');
        $path = rtrim($dir, '/').'/'.$base.'.log';
        $this->assertTrue(file_exists($path));
        $this->assertStringContainsString('hello custom', (string) file_get_contents($path));
    }

    public function testLoggerFallsBackToTmp(): void
    {
        // Attempt writing to a path we cannot write; should fall back to /tmp/<base>.log
        $logger = new \Logger('/no/perm/logger-fallback-test.php', '/');
        $logger->msg('fallback line');
        $path = '/tmp/'.basename('/no/perm/logger-fallback-test.php', '.php').'.log';
        $this->assertTrue(file_exists($path));
        $this->assertStringContainsString('fallback line', (string) file_get_contents($path));
    }

    public function testLogMsgWrapperFallbackToTmp(): void
    {
        // update.php registers its own logmsg helper; verify it falls back to /tmp when primary is unavailable
        $_SERVER['SCRIPT_NAME'] = __DIR__.'/Runner.php';
        $fallbackPath = '/tmp/'.basename($_SERVER['SCRIPT_NAME'], '.php').'.log';

        @unlink($fallbackPath);
        \logmsg('wrapper line');

        $this->assertTrue(file_exists($fallbackPath));
        $this->assertStringContainsString('wrapper line', (string) file_get_contents($fallbackPath));
        @unlink($fallbackPath);
    }

    public function testLoggerSupportsCustomBaseName(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-logs-custom-', 0700);
        $logger = new \Logger(__FILE__, $dir, $dir, 'pmss-update');
        $logger->msg('custom base');

        $path = $dir.'/pmss-update.log';
        $this->assertTrue(file_exists($path));
        $this->assertStringContainsString('custom base', (string) file_get_contents($path));
    }
}
