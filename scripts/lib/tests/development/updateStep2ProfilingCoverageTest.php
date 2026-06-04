<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateStep2ProfilingCoverageTest extends TestCase
{
    public function testUpdateStep2UsesProfiledWrappersForModuleCalls(): void
    {
        $removedWrapper = 'function pmssUpdateStep2Run'.'ClassifiedCallable(';

        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/runtime/profile.php', [
            'function pmssRunProfiledStep(',
            'function pmssRunProfiledCallable(',
            'function pmssRunProfiledCallableBatch(',
        ]);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/util/update-step2.php', [
            'Preparing noninteractive apt defaults',
            'Refreshing package repositories',
            'Applying distro dpkg baseline selections',
            'pmssRunProfiledCallableBatch([',
            'Configuring web stack',
            'Updating all user environments',
            'Ensuring network template baseline',
        ], [
            '#TODO profiling (GH #120)' => 'Profiling TODO marker should be removed once coverage is wired',
            $removedWrapper => 'Classified callable handling should be folded into pmssRunProfiledCallable',
        ]);
    }

    public function testUpdateStep2EmitsProfileSummaryAfterFinalWork(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/util/update-step2.php', [
            "runStep('Refreshing root cron configuration'",
            "pmssRunProfiledCallable('Refreshing MOTD'",
            'pmssProfileSummary();',
        ], 'Missing final profiling step: ', 'Profile summary should run after: ');
    }
}
