<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/userSystemdWatchdog.php';
require_once __DIR__.'/../common/TestCase.php';

class UserSystemdWatchdogTest extends TestCase
{
    public function testUnitNamesOnlyReturnsAccountAuthoredServices(): void
    {
        $home = $this->pmssMakeTempDir('user-systemd-watchdog-units-');
        $this->pmssWriteFiles($home.'/.config/systemd/user', [
            'custom.service' => '[Unit]', 'docker.service' => '[Unit]', 'custom.timer' => '[Unit]',
        ]);

        $this->assertSame(['custom.service'], \pmssUserSystemdWatchdogUnitNames($home));
    }

    public function testUnitNamesRejectsMissingAndSymlinkedDirectories(): void
    {
        $home = $this->pmssMakeTempDir('user-systemd-watchdog-unsafe-');
        $target = $this->pmssMakeTempDir('user-systemd-watchdog-target-');
        $this->pmssWriteRelativeFile($target, 'user/custom.service', '[Unit]');
        $this->assertSame([], \pmssUserSystemdWatchdogUnitNames($home));

        $this->pmssEnsureDir($home.'/.config');
        $this->pmssCreateSymlinkOrSkip($target, $home.'/.config/systemd');
        $this->assertSame([], \pmssUserSystemdWatchdogUnitNames($home));
    }

    public function testObservationRequiresLingerUnitsAndProductionCgroupMode(): void
    {
        $units = ['custom.service'];
        $this->assertTrue(\pmssUserSystemdWatchdogShouldObserve(true, $units, 'v1'));
        $this->assertFalse(\pmssUserSystemdWatchdogShouldObserve(false, $units, 'v1'));
        $this->assertFalse(\pmssUserSystemdWatchdogShouldObserve(true, [], 'v1'));
        $this->assertFalse(\pmssUserSystemdWatchdogShouldObserve(true, $units, 'v2'));
        $this->assertFalse(\pmssUserSystemdWatchdogShouldObserve(true, $units, 'unknown'));
    }

    public function testManagerStateNormalizesExpectedSystemctlResults(): void
    {
        foreach ([
            [['rc' => 0, 'stdout' => "active\n"], 'active'],
            [['rc' => 3, 'stdout' => "inactive\n"], 'inactive'],
            [['rc' => 3, 'stdout' => "failed\n"], 'inactive'],
            [['rc' => 0, 'stdout' => "activating\n"], 'unknown'],
            [['rc' => 4, 'stdout' => "unknown\n"], 'unknown'],
        ] as $case) {
            $actual = \pmssUserSystemdWatchdogManagerState(1001, self::pmssMakeCallbacks(['probe' => $case[0]])['probe']);
            $this->assertSame($case[1], $actual);
        }
        $this->assertSame('unknown', \pmssUserSystemdWatchdogManagerState(0));
    }

    public function testSnapshotDebouncesInactiveManagerAcrossTwoObservations(): void
    {
        $first = \pmssUserSystemdWatchdogSnapshot('fixtur01', 1, 'inactive');
        $second = \pmssUserSystemdWatchdogSnapshot('fixtur01', 1, 'inactive', $first);

        $this->assertSame('pending', $first['state']);
        $this->pmssAssertArraySubsetSame(['state' => 'degraded', 'restartPolicy' => 'observe-only', 'accountUnitCount' => 1], $second);
    }

    public function testSnapshotResetsDebounceAndFailsSoftOnUnknown(): void
    {
        $previous = \pmssUserSystemdWatchdogSnapshot('fixtur01', 1, 'inactive');
        $healthy = \pmssUserSystemdWatchdogSnapshot('fixtur01', 1, 'active', $previous);
        $unknown = \pmssUserSystemdWatchdogSnapshot('fixtur01', 1, 'unknown', $previous);

        $this->assertSame('healthy', $healthy['state']);
        $this->assertSame('unknown', $unknown['state']);
    }

    public function testPendingObservationAndUnannouncedRecoveryStayQuiet(): void
    {
        [, $output] = $this->pmssCaptureStdout(static function (): void {
            \pmssUserSystemdWatchdogLogTransition('fixtur01', ['state' => 'pending'], []);
            \pmssUserSystemdWatchdogLogTransition('fixtur01', ['state' => 'healthy'], ['state' => 'pending']);
        });

        $this->assertSame('', $output);
    }

    public function testStatusWriteIsAtomicReadableAndCustomerReadable(): void
    {
        $home = $this->pmssMakeTempDir('user-systemd-watchdog-status-');
        $path = \pmssUserSystemdWatchdogStatusPath($home);
        $status = \pmssUserSystemdWatchdogSnapshot('fixtur01', 1, 'active');

        $this->assertTrue(\pmssUserSystemdWatchdogStatusWrite($home, $path, $status));
        $this->assertEquals($status, \pmssJsonFileReadAssoc($path, true));
        $this->assertSame(0644, fileperms($path) & 0777);
        $this->assertFalse(\pmssUserSystemdWatchdogStatusWrite($home, '/tmp/outside.json', $status));
    }

    public function testRunUserPublishesOnlyForEligibleAccounts(): void
    {
        $homeRoot = $this->pmssMakeTempDir('user-systemd-watchdog-homes-');
        $lingerRoot = $this->pmssMakeTempDir('user-systemd-watchdog-linger-');
        $this->pmssWriteRelativeFile($homeRoot.'/fixtur01', '.config/systemd/user/custom.service', '[Unit]');
        $this->pmssWriteRelativeFile($lingerRoot, 'fixtur01', '');
        $ops = self::pmssMakeCallbacks(['lookup' => ['uid' => 1001], 'probe' => ['rc' => 0, 'stdout' => "active\n"]]);
        $status = null;

        $this->pmssWithEnv(['PMSS_CGROUP_MODE' => 'v1'], static function () use (&$status, $homeRoot, $lingerRoot, $ops): void {
            $status = \pmssUserSystemdWatchdogRunUser('fixtur01', $homeRoot, $lingerRoot, $ops['probe'], $ops['lookup']);
        });

        $this->assertSame('healthy', $status['state']);
        $this->assertTrue(is_file($homeRoot.'/fixtur01/.systemd-user-status.json'));
        $this->assertSame(null, \pmssUserSystemdWatchdogRunUser('bad-name', $homeRoot, $lingerRoot, $ops['probe'], $ops['lookup']));
    }

    public function testRunUserSkipsAccountsWithoutIntentAndCgroupV2(): void
    {
        $homeRoot = $this->pmssMakeTempDir('user-systemd-watchdog-skip-homes-');
        $lingerRoot = $this->pmssMakeTempDir('user-systemd-watchdog-skip-linger-');
        $this->pmssWriteRelativeFile($homeRoot.'/fixtur01', '.config/systemd/user/docker.service', '[Unit]');
        $this->pmssWriteRelativeFile($lingerRoot, 'fixtur01', '');
        $probe = static function (): array { throw new \RuntimeException('probe must not run'); };
        $lookup = self::pmssMakeCallbacks(['lookup' => ['uid' => 1001]])['lookup'];

        $this->pmssWithEnv(['PMSS_CGROUP_MODE' => 'v1'], function () use ($homeRoot, $lingerRoot, $probe, $lookup): void {
            $this->assertSame(null, \pmssUserSystemdWatchdogRunUser('fixtur01', $homeRoot, $lingerRoot, $probe, $lookup));
        });
        $this->pmssWriteRelativeFile($homeRoot.'/fixtur01', '.config/systemd/user/custom.service', '[Unit]');
        $this->pmssWithEnv(['PMSS_CGROUP_MODE' => 'v2'], function () use ($homeRoot, $lingerRoot, $probe, $lookup): void {
            $this->assertSame(null, \pmssUserSystemdWatchdogRunUser('fixtur01', $homeRoot, $lingerRoot, $probe, $lookup));
        });
    }

    public function testCronAndSkeletonUseTheExistingPerUserFlow(): void
    {
        $cron = $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/cron/mediaStackInstancesCheck.php',
            ["require_once __DIR__.'/../lib/userSystemdWatchdog.php';"]);
        $this->assertOrderedStrings(['pmssMediaStackWatchdogRunUser(', 'pmssUserSystemdWatchdogRunUser('], $cron);
        $this->pmssAssertRepoFileContainsString('etc/skel/.config/systemd/user/.gitkeep', 'standard systemd user-unit directory');
    }
}
