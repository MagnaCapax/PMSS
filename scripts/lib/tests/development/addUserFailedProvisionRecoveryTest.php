<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/add/provisioningRuntime.php';
require_once dirname(__DIR__, 2).'/user/add/orphanCleanup.php';
require_once dirname(__DIR__, 2).'/user/add/failureRollback.php';

final class AddUserFailedProvisionRecoveryTest extends TestCase
{
    public function testLatestProvisionSummaryReadsNewestJsonLineForUser(): void
    {
        $logPath = $this->pmssMakeTempFile('pmss-adduser-log-');
        $lines = array(
            '2026-04-01 10:00:00 (fixtur01): ###ADDUSER_JSON:{"event":"adduser_summary","status":"ERROR","user":"fixtur01","message":"user_exists"}',
            '2026-04-01 10:05:00 (fixtur02): ###ADDUSER_JSON:{"event":"adduser_summary","status":"FAIL","user":"fixtur02","message":"user_config_failed"}',
            '2026-04-01 10:10:00 (fixtur01): ###ADDUSER_JSON:{"event":"adduser_summary","status":"FAIL","user":"fixtur01","message":"password_failed"}',
        );
        file_put_contents($logPath, implode(PHP_EOL, $lines).PHP_EOL);

        $this->pmssWithEnv(array('PMSS_ADDUSER_LOG_PATH' => $logPath), function (): void {
            $summary = pmssAddUserLatestProvisionSummary('fixtur01');
            $this->assertTrue(is_array($summary), 'Expected latest addUser summary for fixtur01');
            $this->assertSame('FAIL', $summary['status']);
            $this->assertSame('password_failed', $summary['message']);
            $this->assertTrue((int) $summary['timestamp'] > 0, 'Expected parsed summary timestamp');
        });
    }

    public function testProvisionSummaryRecoverableRejectsGuardErrors(): void
    {
        $summary = array('status' => 'ERROR', 'timestamp' => 200);
        $this->assertFalse(pmssAddUserProvisionSummaryRecoverable($summary, 250));
    }

    public function testProvisionSummaryRecoverableRejectsStaleFailures(): void
    {
        $summary = array('status' => 'FAIL', 'timestamp' => 100);
        $this->pmssWithEnv(array('PMSS_ADDUSER_RECOVERY_WINDOW_SECONDS' => '300'), function () use ($summary): void {
            $this->assertFalse(pmssAddUserProvisionSummaryRecoverable($summary, 500));
        });
    }

    public function testProvisionSummaryRecoverableRejectsFutureFailures(): void
    {
        $summary = array('status' => 'FAIL', 'timestamp' => 501);
        $this->assertFalse(pmssAddUserProvisionSummaryRecoverable($summary, 500));
    }

    public function testFailedProvisionCanRecoverRequiresInactiveServices(): void
    {
        $summary = array('status' => 'FAIL', 'timestamp' => 1000);
        $this->assertFalse(
            pmssAddUserFailedProvisionCanRecover(
                'fixtur01',
                $summary,
                array('rtorrent' => true, 'lighttpd' => false),
                1100
            )
        );
        $this->assertTrue(
            pmssAddUserFailedProvisionCanRecover(
                'fixtur01',
                $summary,
                array('rtorrent' => false, 'lighttpd' => false),
                1100
            )
        );
    }

    public function testCleanupTargetValidationRejectsUnsafePaths(): void
    {
        $this->assertTrue(pmssAddUserCleanupFailedProvisionTargetValid('fixtur01', '/home/fixtur01'));

        foreach (array(
            array('Fixtur01', '/home/Fixtur01'),
            array('fixtur01', '/home/fixtur02'),
            array('fixtur01', '/home'),
            array('fixtur01', '/'),
            array('fixtur01', '/home/fixtur01/../fixtur02'),
            array('fixtur01-', '/home/fixtur01-'),
            array('fixtur01', "\0/home/fixtur01"),
        ) as $case) {
            $this->assertFalse(pmssAddUserCleanupFailedProvisionTargetValid($case[0], $case[1]));
        }
    }

    public function testCleanupTargetValidationRejectsUnsafePathState(): void
    {
        $checkedPath = null;
        $this->assertFalse(pmssAddUserCleanupFailedProvisionTargetValid('fixtur01', '/home/fixtur01', static function (string $homePath, bool $directoryTarget, bool $requireParentDirectory, bool $allowEmptySegments) use (&$checkedPath): bool {
            $checkedPath = array($homePath, $directoryTarget, $requireParentDirectory, $allowEmptySegments);
            return false;
        }));
        $this->assertSame(array('/home/fixtur01', true, false, false), $checkedPath);
    }

    public function testCleanupCommandsKeepExpectedRecoverySteps(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/user/add/orphanCleanup.php');

        $this->assertOrderedStrings(
            array(
                'Kill lingering user processes',
                'Release lighttpd port',
                'Disable systemd linger',
                'Delete user account and home',
                'Delete user group',
                'Cleanup addUser lock files',
            ),
            $source
        );
        $this->assertStringContainsAllStrings(['/scripts/util/portManager.php release', 'userdel -r', "'/etc/seedbox/runtime/trafficLimits/'.\$userName", 'pmssAddUserCleanupFailedProvisionTargetValid($userName, $homePath)', "'pmssPathTargetIsSafe'"], $source);
        $this->assertStringContainsString('timeout --kill-after=5s 45s loginctl disable-linger', $source);
    }

    public function testCleanupStepFailureRunsRemainingStepsAndFailsClosed(): void
    {
        $calls = array();
        $steps = array(
            array('first', 'command-one'),
            array('second', 'command-two'),
            array('third', 'command-three'),
        );
        $this->assertFalse(pmssAddUserCleanupStepsRun($steps, static function (string $description, string $command) use (&$calls): int {
            $calls[] = array($description, $command);
            return $description === 'second' ? 9 : 0;
        }));
        $this->assertSame($steps, $calls);
        $this->assertTrue(pmssAddUserCleanupStepsRun($steps, static function (): int {
            return 0;
        }));
    }

    public function testFailureRollbackRunsOnlyForEarlyFailAfterUserCreation(): void
    {
        $summary = array('status' => 'FAIL', 'exit_code' => 1);

        $this->assertTrue(pmssAddUserFailureRollbackShouldRun(
            array('systemUserCreated' => true, 'userConfigApplied' => false, 'cleanupAttempted' => false),
            $summary
        ));
        $this->assertFalse(pmssAddUserFailureRollbackShouldRun(
            array('systemUserCreated' => false, 'userConfigApplied' => false, 'cleanupAttempted' => false),
            $summary
        ));
        $this->assertFalse(pmssAddUserFailureRollbackShouldRun(
            array('systemUserCreated' => true, 'userConfigApplied' => true, 'cleanupAttempted' => false),
            $summary
        ));
        $this->assertFalse(pmssAddUserFailureRollbackShouldRun(
            array('systemUserCreated' => true, 'userConfigApplied' => false, 'cleanupAttempted' => true),
            $summary
        ));
    }

    public function testAddUserWrapperUsesPreflightHelper(): void
    {
        $src = $this->pmssReadRepoFile('scripts/addUser.php');
        $this->assertStringContainsAllStrings(["require_once __DIR__.'/lib/user/add/preflight.php';", 'pmssAddUserEnsurePreflightState($userDb, $user, $homePath);'], $src);
    }

    public function testAddUserWrapperInitializesFailureRollback(): void
    {
        $src = $this->pmssReadRepoFile('scripts/addUser.php');
        $this->assertStringContainsAllStrings(["require_once __DIR__.'/lib/user/add/failureRollback.php';", "pmssAddUserFailureRollbackInit(\$userDb, \$user['name'], \$homePath);"], $src);
    }

    public function testProvisioningPhasesMarkRollbackBoundaries(): void
    {
        $this->assertStringContainsString('pmssAddUserFailureRollbackMarkSystemUserCreated();', $this->pmssReadRepoFile('scripts/lib/user/add/systemUserCreate.php'));
        $this->assertStringContainsString('pmssAddUserFailureRollbackMarkUserConfigApplied();', $this->pmssReadRepoFile('scripts/lib/user/add/userConfigApply.php'));
    }

    public function testSystemUserCreateConvergesCredentialDirectoryBeforePasswordSync(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/lib/user/add/systemUserCreate.php',
            array(
                "pmssEnsureUserHomeDir(\$user['name'], \$homePath, '.lighttpd', 0751, 'logProvisionMessage')",
                "'lighttpd_directory_failed'",
                '$safeChangePw = sprintf(',
                "'Set initial password'",
            )
        );
    }
}
