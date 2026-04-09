<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateStep2ErrorHandlingPolicyWiringTest extends TestCase
{
    public function testStepPolicyRuntimeDefinesClassificationConstants(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/runtime/stepPolicy.php');

        $this->assertStringContainsString('PMSS_UPDATE_STEP_CLASS_SOFT_FAIL', $src);
        $this->assertStringContainsString('PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED', $src);
        $this->assertStringContainsString('PMSS_UPDATE_STEP_CLASS_SKIP_IF_MISSING', $src);
        $this->assertStringContainsString('pmssUpdateStep2HandleClassifiedFailure', $src);
    }

    public function testUpdateStep2MarksCriticalPostPackageStepsMustSucceed(): void
    {
        $src = $this->pmssReadRepoFile('scripts/util/update-step2.php');

        $this->assertStringContainsString("require_once __DIR__.'/../lib/update/runtime/stepPolicy.php';", $src);
        $this->assertStringContainsString("pmssUpdateStep2RunClassifiedCallable('Applying runtime service templates'", $src);
        $this->assertStringContainsString("pmssUpdateStep2RunClassifiedCallable('Configuring web stack'", $src);
        $this->assertTrue(
            substr_count($src, 'PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED') >= 2,
            'Expected at least two MUST_SUCCEED annotations in update-step2'
        );
    }
}
