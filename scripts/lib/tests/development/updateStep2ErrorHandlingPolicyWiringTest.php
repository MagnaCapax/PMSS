<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateStep2ErrorHandlingPolicyWiringTest extends TestCase
{
    public function testStepPolicyRuntimeDefinesClassificationConstants(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/lib/update/runtime/stepPolicy.php';
        $src = @file_get_contents($path);
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertStringContainsString('PMSS_UPDATE_STEP_CLASS_SOFT_FAIL', $src);
        $this->assertStringContainsString('PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED', $src);
        $this->assertStringContainsString('PMSS_UPDATE_STEP_CLASS_SKIP_IF_MISSING', $src);
        $this->assertStringContainsString('pmssUpdateStep2HandleClassifiedFailure', $src);
    }

    public function testUpdateStep2MarksCriticalPostPackageStepsMustSucceed(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/util/update-step2.php';
        $src = @file_get_contents($path);
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertStringContainsString("require_once __DIR__.'/../lib/update/runtime/stepPolicy.php';", $src);
        $this->assertStringContainsString("pmssUpdateStep2RunClassifiedCallable('Applying runtime service templates'", $src);
        $this->assertStringContainsString("pmssUpdateStep2RunClassifiedCallable('Configuring web stack'", $src);
        $this->assertStringContainsString("pmssUpdateStep2RunClassifiedCallable('Ensuring sshd AuthorizedKeysFile directive'", $src);
        $this->assertTrue(
            substr_count($src, 'PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED') >= 3,
            'Expected at least three MUST_SUCCEED annotations in update-step2'
        );
    }
}
