<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/update/runtime/stepPolicy.php';
require_once __DIR__.'/../common/TestCase.php';

class UpdateStep2ErrorHandlingPolicyWiringTest extends TestCase
{
    public function testStepPolicyWiringContractsStayStable(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/update/runtime/stepPolicy.php' => ['required' => ['PMSS_UPDATE_STEP_CLASS_SOFT_FAIL', 'PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED', 'PMSS_UPDATE_STEP_CLASS_SKIP_IF_MISSING', 'pmssUpdateStep2ClassificationIsKnown', 'unknown_classification', 'pmssUpdateStep2HandleClassifiedFailure']],
            'scripts/util/update-step2.php' => ['required' => ["require_once __DIR__.'/../lib/update/runtime/stepPolicy.php';", "pmssRunProfiledCallable('Applying runtime service templates', 'pmssApplyRuntimeTemplates', [], PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED);", "pmssRunProfiledCallable('Configuring web stack', 'pmssConfigureWebStack', [], PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED);"]],
        ]);
        $this->pmssAssertRepoFileSubstringCountAtLeast(
            'scripts/util/update-step2.php',
            'PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED',
            2,
            'Expected at least two MUST_SUCCEED annotations in update-step2'
        );
    }

    public function testStepPolicyClassificationsAreExplicitlyAllowlisted(): void
    {
        foreach ([
            \PMSS_UPDATE_STEP_CLASS_SOFT_FAIL => true,
            \PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED => true,
            \PMSS_UPDATE_STEP_CLASS_SKIP_IF_MISSING => true,
            '' => false,
            'must-success' => false,
            'warning' => false,
            "soft_fail\n" => false,
        ] as $classification => $expected) {
            $this->assertSame($expected, \pmssUpdateStep2ClassificationIsKnown($classification), $classification);
        }
    }
}
