<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateStep2ProfilingCoverageTest extends TestCase
{
    public function testUpdateStep2UsesProfiledWrappersForModuleCalls(): void
    {
        $removedWrapper = 'function pmssUpdateStep2Run'.'ClassifiedCallable(';

        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/util/update-step2.php', [
            'function pmssRunProfiledStep(',
            'function pmssRunProfiledCallable(',
            'Preparing noninteractive apt defaults',
            'Refreshing package repositories',
            'Applying distro dpkg baseline selections',
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
        $src = $this->pmssReadRepoFile('scripts/util/update-step2.php');

        $summaryIndex = strpos($src, 'pmssProfileSummary();');
        $rootCronIndex = strpos($src, "runStep('Refreshing root cron configuration'");
        $motdIndex = strpos($src, "pmssRunProfiledCallable('Refreshing MOTD'");

        $this->assertTrue($summaryIndex !== false, 'Expected pmssProfileSummary() call in update-step2.php');
        $this->assertTrue($rootCronIndex !== false, 'Expected root cron refresh step in update-step2.php');
        $this->assertTrue($motdIndex !== false, 'Expected profiled MOTD refresh step in update-step2.php');
        $this->assertTrue($summaryIndex > $rootCronIndex, 'Profile summary should run after root cron refresh');
        $this->assertTrue($summaryIndex > $motdIndex, 'Profile summary should run after MOTD refresh');
    }
}
