<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateStep2ErrorHandlingPolicyWiringTest extends TestCase
{
    public function testStepPolicyRuntimeDefinesClassificationConstants(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/runtime/stepPolicy.php', ['PMSS_UPDATE_STEP_CLASS_SOFT_FAIL', 'PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED', 'PMSS_UPDATE_STEP_CLASS_SKIP_IF_MISSING', 'pmssUpdateStep2HandleClassifiedFailure']);
    }

    public function testUpdateStep2MarksCriticalPostPackageStepsMustSucceed(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/update-step2.php', ["require_once __DIR__.'/../lib/update/runtime/stepPolicy.php';", "pmssUpdateStep2RunClassifiedCallable('Applying runtime service templates'", "pmssUpdateStep2RunClassifiedCallable('Configuring web stack'"]);
        $this->pmssAssertRepoFileSubstringCountAtLeast(
            'scripts/util/update-step2.php',
            'PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED',
            2,
            'Expected at least two MUST_SUCCEED annotations in update-step2'
        );
    }
}
