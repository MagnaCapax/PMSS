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
            '2026-04-01 10:00:00 (alice): ###ADDUSER_JSON:{"event":"adduser_summary","status":"ERROR","user":"alice","message":"user_exists"}',
            '2026-04-01 10:05:00 (bob): ###ADDUSER_JSON:{"event":"adduser_summary","status":"FAIL","user":"bob","message":"user_config_failed"}',
            '2026-04-01 10:10:00 (alice): ###ADDUSER_JSON:{"event":"adduser_summary","status":"FAIL","user":"alice","message":"password_failed"}',
        );
        file_put_contents($logPath, implode(PHP_EOL, $lines).PHP_EOL);

        $this->pmssWithEnv(array('PMSS_ADDUSER_LOG_PATH' => $logPath), function (): void {
            $summary = pmssAddUserLatestProvisionSummary('alice');
            $this->assertTrue(is_array($summary), 'Expected latest addUser summary for alice');
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

    public function testFailedProvisionCanRecoverRequiresInactiveServices(): void
    {
        $summary = array('status' => 'FAIL', 'timestamp' => 1000);
        $this->assertFalse(
            pmssAddUserFailedProvisionCanRecover(
                'alice',
                $summary,
                array('rtorrent' => true, 'lighttpd' => false),
                1100
            )
        );
        $this->assertTrue(
            pmssAddUserFailedProvisionCanRecover(
                'alice',
                $summary,
                array('rtorrent' => false, 'lighttpd' => false),
                1100
            )
        );
    }

    public function testCleanupTargetValidationRejectsUnsafePaths(): void
    {
        $this->assertTrue(pmssAddUserCleanupFailedProvisionTargetValid('alice', '/home/alice'));

        foreach (array(
            array('Alice', '/home/Alice'),
            array('alice', '/home/bob'),
            array('alice', '/home'),
            array('alice', '/'),
            array('alice', '/home/alice/../bob'),
            array('alice-', '/home/alice-'),
            array('alice', "\0/home/alice"),
        ) as $case) {
            $this->assertFalse(pmssAddUserCleanupFailedProvisionTargetValid($case[0], $case[1]));
        }
    }

    public function testCleanupTargetValidationRejectsUnsafePathState(): void
    {
        $checkedPath = null;
        $checker = static function (string $homePath, bool $directoryTarget, bool $requireParentDirectory, bool $allowEmptySegments) use (&$checkedPath): bool {
            $checkedPath = array($homePath, $directoryTarget, $requireParentDirectory, $allowEmptySegments);
            return false;
        };

        $this->assertFalse(pmssAddUserCleanupFailedProvisionTargetValid('alice', '/home/alice', $checker));
        $this->assertSame(array('/home/alice', true, false, false), $checkedPath);
    }

    public function testCleanupCommandsKeepExpectedRecoverySteps(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/user/add/orphanCleanup.php');

        $this->assertOrderedStrings(
            array(
                'Kill lingering user processes',
                'Release lighttpd port',
                'Delete user account and home',
                'Delete user group',
                'Cleanup addUser lock files',
            ),
            $source
        );
        $this->assertStringContainsAllStrings(['/scripts/util/portManager.php release', 'userdel -r', "'/etc/seedbox/runtime/trafficLimits/'.\$userName", 'pmssAddUserCleanupFailedProvisionTargetValid($userName, $homePath)', "'pmssPathTargetIsSafe'"], $source);
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
        $this->assertStringContainsAllStrings(["require_once 'lib/user/add/preflight.php';", 'pmssAddUserEnsurePreflightState($userDb, $user, $homePath);'], $src);
    }

    public function testAddUserWrapperInitializesFailureRollback(): void
    {
        $src = $this->pmssReadRepoFile('scripts/addUser.php');
        $this->assertStringContainsAllStrings(["require_once 'lib/user/add/failureRollback.php';", "pmssAddUserFailureRollbackInit(\$userDb, \$user['name'], \$homePath);"], $src);
    }

    public function testProvisioningPhasesMarkRollbackBoundaries(): void
    {
        $systemUserCreate = $this->pmssReadRepoFile('scripts/lib/user/add/systemUserCreate.php');
        $userConfigApply = $this->pmssReadRepoFile('scripts/lib/user/add/userConfigApply.php');

        $this->assertStringContainsString('pmssAddUserFailureRollbackMarkSystemUserCreated();', $systemUserCreate);
        $this->assertStringContainsString('pmssAddUserFailureRollbackMarkUserConfigApplied();', $userConfigApply);
    }
}
