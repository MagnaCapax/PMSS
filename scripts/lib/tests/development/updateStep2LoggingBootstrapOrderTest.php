<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateStep2LoggingBootstrapOrderTest extends TestCase
{
    public function testStandaloneLogmsgFallbackPrecedesFirstProfiledStep(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/util/update-step2.php';
        $src = @file_get_contents($path);
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $fallbackIndex = strpos($src, "if (!function_exists('logmsg')) {");
        $firstStepIndex = strpos($src, "pmssRunProfiledCallable('Acquiring update-step2 lock'");

        $this->assertTrue($fallbackIndex !== false, 'Expected standalone logmsg fallback in update-step2.php');
        $this->assertTrue($firstStepIndex !== false, 'Expected lock acquisition step in update-step2.php');
        $this->assertTrue(
            $fallbackIndex < $firstStepIndex,
            'Standalone logmsg fallback must be defined before the first profiled step runs'
        );
    }
}
