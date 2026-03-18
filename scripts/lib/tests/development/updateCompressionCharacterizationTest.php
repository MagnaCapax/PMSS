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

    public function testKillProcessKeepsProcessProbeLocal(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/update/runtime/processes.php';
        $src = @file_get_contents($path);
        $symbol = 'pmssProcess'.'Running';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(
            strpos($src, 'function '.$symbol.'(') === false,
            'process presence checks should stay localized inside killProcess()'
        );
        $this->assertStringContainsString("exec('pgrep -x '.escapeshellarg(\$name).' >/dev/null 2>&1'", $src);
        $this->assertStringContainsString('[SKIP] {$description} (no {$name} processes)', $src);
    }

    public function testUpdateStep2KeepsMediaareaBootstrapCleanupInline(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/util/update-step2.php';
        $src = @file_get_contents($path);
        $symbol = 'pmssCleanup'.'MediaareaBootstrapPackage';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertStringContainsString("pmssRunProfiledStep('Cleaning mediaarea bootstrap package state'", $src);
        $this->assertStringContainsString("dpkg-query -W -f=\${Status} repo-mediaarea 2>/dev/null", $src);
        $this->assertStringContainsString("runStep('Marking repo-mediaarea for deinstallation', \$setSelection);", $src);
        $this->assertTrue(
            strpos($src, $symbol) === false,
            'update-step2.php should own the mediaarea bootstrap cleanup directly'
        );
    }

    public function testRootlessDockerUnitParsingStaysInsideUserMaintenance(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/update/userMaintenance.php';
        $src = @file_get_contents($path);
        $symbol = 'pmssReadSystemd'.'UnitExecStartBinary';
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(
            strpos($src, 'function '.$symbol) === false,
            'userMaintenance.php should keep the docker ExecStart parse local to the stale-unit check'
        );
        $this->assertStringContainsString("if (strpos(\$trim, 'ExecStart=') !== 0)", $src);
        $this->assertStringContainsString("\$execBinary = trim(\$parts[0], \"\\\"'\");", $src);
    }
}
