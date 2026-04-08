<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateStep2LoggingBootstrapOrderTest extends TestCase
{
    public function testStandaloneLogmsgDefaultsBootstrapPrecedesFirstProfiledStep(): void
    {
        $src = $this->pmssReadRepoFile('scripts/util/update-step2.php');

        $loggerRequireIndex = strpos($src, "require_once __DIR__.'/../lib/log.php';");
        $loggerBootstrapIndex = strpos($src, "'base_name' => 'pmss-update'");
        $updateLibIndex = strpos($src, "require_once __DIR__.'/../lib/update.php';");
        $legacyFallbackIndex = strpos($src, "if (!function_exists('logmsg')) {");
        $firstStepIndex = strpos($src, "pmssRunProfiledCallable('Acquiring update-step2 lock'");
        $legacyObjectIndex = strpos($src, 'logmsg'.'_default_logger');
        $loggerClassIndex = strpos($src, 'new Logger(');

        $this->assertTrue($loggerRequireIndex !== false, 'Expected shared log bootstrap import in update-step2.php');
        $this->assertTrue($loggerBootstrapIndex !== false, 'Expected shared logmsg defaults bootstrap in update-step2.php');
        $this->assertTrue($updateLibIndex !== false, 'Expected update helper include in update-step2.php');
        $this->assertTrue($firstStepIndex !== false, 'Expected lock acquisition step in update-step2.php');
        $this->assertTrue(
            $loggerRequireIndex < $updateLibIndex,
            'Shared log bootstrap should load before update helpers'
        );
        $this->assertTrue(
            $loggerBootstrapIndex < $firstStepIndex,
            'Shared logmsg defaults must be configured before the first profiled step runs'
        );
        $this->assertTrue(
            $legacyFallbackIndex === false,
            'update-step2.php should rely on the shared log bootstrap instead of a local logmsg fallback'
        );
        $this->assertTrue(
            $legacyObjectIndex === false,
            'update-step2.php should not keep a cached Logger object for legacy logmsg() anymore'
        );
        $this->assertTrue(
            $loggerClassIndex === false,
            'update-step2.php should not instantiate Logger just to configure legacy logmsg()'
        );
    }
}
