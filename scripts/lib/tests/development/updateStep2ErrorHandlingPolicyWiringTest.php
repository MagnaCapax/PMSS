<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateStep2ErrorHandlingPolicyWiringTest extends TestCase
{
    public function testStepPolicyRuntimeDefinesClassificationConstants(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/runtime/stepPolicy.php');

        $this->assertStringContainsAllStrings(['PMSS_UPDATE_STEP_CLASS_SOFT_FAIL', 'PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED', 'PMSS_UPDATE_STEP_CLASS_SKIP_IF_MISSING', 'pmssUpdateStep2HandleClassifiedFailure'], $src);
    }

    public function testUpdateStep2MarksCriticalPostPackageStepsMustSucceed(): void
    {
        $src = $this->pmssReadRepoFile('scripts/util/update-step2.php');

        $this->assertStringContainsAllStrings(["require_once __DIR__.'/../lib/update/runtime/stepPolicy.php';", "pmssUpdateStep2RunClassifiedCallable('Applying runtime service templates'", "pmssUpdateStep2RunClassifiedCallable('Configuring web stack'"], $src);
        $this->assertTrue(
            substr_count($src, 'PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED') >= 2,
            'Expected at least two MUST_SUCCEED annotations in update-step2'
        );
    }
}
