<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * Characterize the abnormal-exit nginx refresh guard in update-step2.
 */
class UpdateStep2WebRefreshGuardTest extends TestCase
{
    public function testUpdateStep2RegistersShutdownGuardForWebRefresh(): void
    {
        $source = $this->pmssReadRepoFile('scripts/util/update-step2.php');

        $this->assertStringContainsString('function pmssUpdateStep2RegisterWebRefreshShutdownGuard(): void', $source);
        $this->assertStringContainsString('pmssUpdateStep2RegisterWebRefreshShutdownGuard();', $source);
        $this->assertStringContainsString('pmssUpdateStep2MarkWebRefreshRequired();', $source);
        $this->assertStringContainsString('pmssUpdateStep2MarkWebRefreshCompleted();', $source);
        $this->assertStringContainsString("/scripts/util/createNginxConfig.php --restart", $source);
        $this->assertStringContainsString("'PMSS_UPDATE_STEP2_COMPLETED'", $source);
    }
}
