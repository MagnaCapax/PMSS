<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateStep2LoggingBootstrapOrderTest extends TestCase
{
    public function testStandaloneLoggerBootstrapPrecedesFirstProfiledStep(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/util/update-step2.php';
        $src = @file_get_contents($path);
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $loggerRequireIndex = strpos($src, "require_once __DIR__.'/../lib/logger.php';");
        $loggerBootstrapIndex = strpos($src, "new Logger(__FILE__, '/var/log', '/tmp', 'pmss-update', true);");
        $updateLibIndex = strpos($src, "require_once __DIR__.'/../lib/update.php';");
        $legacyFallbackIndex = strpos($src, "if (!function_exists('logmsg')) {");
        $firstStepIndex = strpos($src, "pmssRunProfiledCallable('Acquiring update-step2 lock'");

        $this->assertTrue($loggerRequireIndex !== false, 'Expected shared logger bootstrap import in update-step2.php');
        $this->assertTrue($loggerBootstrapIndex !== false, 'Expected shared Logger bootstrap in update-step2.php');
        $this->assertTrue($updateLibIndex !== false, 'Expected update helper include in update-step2.php');
        $this->assertTrue($firstStepIndex !== false, 'Expected lock acquisition step in update-step2.php');
        $this->assertTrue(
            $loggerRequireIndex < $updateLibIndex,
            'Shared logger bootstrap should load before update helpers'
        );
        $this->assertTrue(
            $loggerBootstrapIndex < $firstStepIndex,
            'Shared Logger bootstrap must be configured before the first profiled step runs'
        );
        $this->assertTrue(
            $legacyFallbackIndex === false,
            'update-step2.php should rely on the shared logger bootstrap instead of a local logmsg fallback'
        );
    }
}
