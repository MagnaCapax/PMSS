<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateStep2ProfilingCoverageTest extends TestCase
{
    public function testUpdateStep2UsesProfiledWrappersForModuleCalls(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/util/update-step2.php';
        $src = @file_get_contents($path);
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(strpos($src, '#TODO profiling (GH #120)') === false, 'Profiling TODO marker should be removed once coverage is wired');
        $this->assertStringContainsString('function pmssRunProfiledStep(', $src, 'Expected callable profiling helper');
        $this->assertStringContainsString('function pmssRunProfiledCallable(', $src, 'Expected callable invocation helper');

        foreach ([
            'Preparing noninteractive apt defaults',
            'Refreshing package repositories',
            'Applying distro dpkg baseline selections',
            'Configuring web stack',
            'Updating all user environments',
            'Ensuring network template baseline',
        ] as $label) {
            $this->assertStringContainsString($label, $src, 'Missing profiled step label: '.$label);
        }
    }

    public function testUpdateStep2EmitsProfileSummaryAfterFinalWork(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/util/update-step2.php';
        $src = @file_get_contents($path);
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

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
