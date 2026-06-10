<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateStep2ProfilingCoverageTest extends TestCase
{
    public function testUpdateStep2UsesProfiledWrappersForModuleCalls(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/update/runtime/profile.php' => ['required' => ['function pmssRunProfiledStep(', 'function pmssRunProfiledCallable(', 'function pmssRunProfiledCallableBatch(']],
            'scripts/util/update-step2.php' => [
                'required' => [
                    'Preparing noninteractive apt defaults',
                    'Refreshing package repositories',
                    'Applying distro dpkg baseline selections',
                    'pmssRunProfiledCallableBatch([',
                    'Configuring web stack',
                    'Updating all user environments',
                    'Ensuring network template baseline',
                ],
                'forbidden' => [
                    '#TODO profiling (GH #120)' => 'Profiling TODO marker should be removed once coverage is wired',
                    'function pmssUpdateStep2Run'.'ClassifiedCallable(' => 'Classified callable handling should be folded into pmssRunProfiledCallable',
                ],
                'ordered' => [[
                    'needles' => [
                        "runStep('Refreshing root cron configuration'",
                        "pmssRunProfiledCallable('Refreshing MOTD'",
                        'pmssProfileSummary();',
                    ],
                    'missingPrefix' => 'Missing final profiling step: ',
                    'orderPrefix' => 'Profile summary should run after: ',
                ]],
            ],
        ]);
    }
}
