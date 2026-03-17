<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateCompressionCharacterizationTest extends TestCase
{
    public function testUpdateStep2KeepsInlineLighttpdHardeningStep(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/util/update-step2.php';
        $src = @file_get_contents($path);
        $symbol = 'pmssAdjust'.'LighttpdSecurity';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertStringContainsString("pmssRunProfiledStep('Adjusting lighttpd security settings'", $src);
        $this->assertStringContainsString("runStep('Restricting /etc/lighttpd directory permissions', 'chmod 750 /etc/lighttpd');", $src);
        $this->assertStringContainsString("logmsg('[SKIP] lighttpd .htpasswd missing; per-user instances manage authentication');", $src);
        $this->assertTrue(
            strpos($src, $symbol) === false,
            'update-step2.php should own the lighttpd hardening block directly'
        );
    }

    public function testWebStackDropsStandaloneLighttpdHardeningHelper(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/update/webStack.php';
        $src = @file_get_contents($path);
        $symbol = 'pmssAdjust'.'LighttpdSecurity';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);
        $this->assertTrue(
            strpos($src, 'function '.$symbol) === false,
            'webStack.php should no longer export a one-use lighttpd hardening helper'
        );
    }

    public function testKillProcessKeepsGracefulAndForcedWaitPhasesLocally(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/update/runtime/processes.php';
        $src = @file_get_contents($path);
        $symbol = 'pmssWaitFor'.'ProcessExit';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(
            strpos($src, 'function '.$symbol) === false,
            'process wait logic should be localized inside killProcess()'
        );
        $this->assertStringContainsString("runStep(\$description.' (SIGTERM)'", $src);
        $this->assertStringContainsString("runStep(\$description.' (SIGKILL)'", $src);
        $this->assertStringContainsString('graceful stop', $src);
        $this->assertStringContainsString('processes linger after SIGKILL', $src);
    }
}
