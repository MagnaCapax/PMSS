<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateBootstrapDistUpgradeTest extends TestCase
{
    public function testBootstrapRestoresCronAfterDistUpgrade(): void
    {
        $this->pmssAssertRepoFileContainsString(
            'scripts/update.php',
            "restoreRootCronBestEffort('dist-upgrade')",
            'dist-upgrade flow should restore root cron (setupRootCron.php) before exiting'
        );
    }

    public function testBootstrapRestoresCronAfterUpdateStep2FailurePath(): void
    {
        $data = $this->pmssReadRepoFile('scripts/update.php');
        $start = strpos($data, 'function runUpdateStep2');
        $end = strpos($data, 'function checkDiskSpace');
        $this->assertTrue($start !== false && $end !== false && $start < $end, 'Expected runUpdateStep2 function block');
        $runUpdateStep2 = substr($data, $start, $end - $start);

        $this->assertOrderedStrings([
            'pmssDisableRootCronForUpdateStep2();',
            "passthru(pmssShellCommandWithoutInheritedUpdateLock(pmssBootstrapPhpCommand('/scripts/util/update-step2.php')), \$rc);",
            "restoreRootCronBestEffort('update-step2 handoff');",
            'if ($rc !== 0) {',
        ], $runUpdateStep2);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/update.php', [
            "register_shutdown_function('pmssRootCronShutdownRestore', 'shutdown')",
            "pcntl_signal(constant(\$signalName), 'pmssRootCronSignalHandler')",
        ]);
    }

    public function testBootstrapDelaysRootCronDisableUntilStep2Handoff(): void
    {
        $data = $this->pmssReadRepoFile('scripts/update.php');
        $runStep2Start = strpos($data, 'function runUpdateStep2');
        $runStep2End = strpos($data, 'function checkDiskSpace');
        $bootstrapStart = strpos($data, 'function bootstrapMain');
        $this->assertTrue($runStep2Start !== false && $runStep2End !== false && $runStep2Start < $runStep2End, 'Expected runUpdateStep2 function block');
        $this->assertTrue($bootstrapStart !== false, 'Expected bootstrapMain function block');

        $runUpdateStep2 = substr($data, $runStep2Start, $runStep2End - $runStep2Start);
        $bootstrapMain = substr($data, $bootstrapStart);
        $disableIdx = strpos($runUpdateStep2, 'pmssDisableRootCronForUpdateStep2();');
        $handoffIdx = strpos($runUpdateStep2, "logEvent('update_step2_start')");
        $this->assertTrue($disableIdx !== false, 'update.php should disable root cron at phase-2 handoff');
        $this->assertTrue($handoffIdx !== false, 'update.php should log update_step2_start');
        $this->assertTrue($handoffIdx < $disableIdx, 'root cron disable should be coupled to update-step2 handoff');
        $this->pmssAssertStringNotContainsString("pmssRemoveFileFatal(\$rootCron", $bootstrapMain, 'bootstrapMain must not remove root cron during phase-1 staging');
        $this->pmssAssertStringNotContainsString('Disabled /etc/cron.d/pmss during update', $bootstrapMain, 'root cron should stay live through snapshot staging');
    }

    public function testCronServiceSelfHealRunsInBootstrapAndStep2(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/update.php', [
            'function pmssEnsureCronServiceActiveBootstrap',
            'systemctl is-enabled cron.service',
            'systemctl unmask cron.service || true',
            'systemctl enable --now cron.service || true',
            "pmssEnsureCronServiceActiveBootstrap('update.php start')",
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/services/systemd.php', [
            'function pmssEnsureCronServiceActive',
            "pmssSystemdUnitState('is-enabled', 'cron.service')",
            'systemctl unmask cron.service || true',
            'systemctl enable --now cron.service || true',
        ]);
        $this->pmssAssertRepoFileContainsString(
            'scripts/util/update-step2.php',
            "pmssRunProfiledCallable('Ensuring cron service is active before root cron restore'"
        );
        $this->pmssAssertRepoFileContainsString(
            'scripts/util/setupRootCron.php',
            "pmssEnsureCronServiceActive('root cron setup')"
        );
    }
}
