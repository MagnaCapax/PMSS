<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/userSystemdWatchdog.php';
require_once __DIR__.'/../common/TestCase.php';

class UserSystemdWatchdogTest extends TestCase
{
    public function testUnitNamesOnlyReturnsAccountAuthoredServices(): void
    {
        $home = $this->pmssMakeTempDir('user-systemd-watchdog-units-');
        $this->pmssWriteRelativeFile($home, '.config/systemd/user/custom.service', '[Unit]');
        $this->pmssWriteRelativeFile($home, '.config/systemd/user/docker.service', '[Unit]');
        $this->pmssWriteRelativeFile($home, '.config/systemd/user/custom.timer', '[Unit]');

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
            $actual = \pmssUserSystemdWatchdogManagerState(1001, static function () use ($case): array {
                return $case[0];
            });
            $this->assertSame($case[1], $actual);
        }
        $this->assertSame('unknown', \pmssUserSystemdWatchdogManagerState(0));
    }

    public function testSnapshotDebouncesInactiveManagerAcrossTwoObservations(): void
    {
        $first = \pmssUserSystemdWatchdogSnapshot('alice', 1, 'inactive');
        $second = \pmssUserSystemdWatchdogSnapshot('alice', 1, 'inactive', $first);

        $this->assertSame('pending', $first['state']);
        $this->assertSame('degraded', $second['state']);
        $this->assertSame('observe-only', $second['restartPolicy']);
        $this->assertSame(1, $second['accountUnitCount']);
    }

    public function testSnapshotResetsDebounceAndFailsSoftOnUnknown(): void
    {
        $previous = \pmssUserSystemdWatchdogSnapshot('alice', 1, 'inactive');
        $healthy = \pmssUserSystemdWatchdogSnapshot('alice', 1, 'active', $previous);
        $unknown = \pmssUserSystemdWatchdogSnapshot('alice', 1, 'unknown', $previous);

        $this->assertSame('healthy', $healthy['state']);
        $this->assertSame('unknown', $unknown['state']);
    }

    public function testPendingObservationAndUnannouncedRecoveryStayQuiet(): void
    {
        ob_start();
        \pmssUserSystemdWatchdogLogTransition('alice', ['state' => 'pending'], []);
        \pmssUserSystemdWatchdogLogTransition('alice', ['state' => 'healthy'], ['state' => 'pending']);
        $output = (string) ob_get_clean();

        $this->assertSame('', $output);
    }

    public function testStatusWriteIsAtomicReadableAndCustomerReadable(): void
    {
        $home = $this->pmssMakeTempDir('user-systemd-watchdog-status-');
        $path = \pmssUserSystemdWatchdogStatusPath($home);
        $status = \pmssUserSystemdWatchdogSnapshot('alice', 1, 'active');

        $this->assertTrue(\pmssUserSystemdWatchdogStatusWrite($home, $path, $status));
        $this->assertEquals($status, \pmssJsonFileReadAssoc($path, true));
        $this->assertSame(0644, fileperms($path) & 0777);
        $this->assertFalse(\pmssUserSystemdWatchdogStatusWrite($home, '/tmp/outside.json', $status));
    }

    public function testRunUserPublishesOnlyForEligibleAccounts(): void
    {
        $homeRoot = $this->pmssMakeTempDir('user-systemd-watchdog-homes-');
        $lingerRoot = $this->pmssMakeTempDir('user-systemd-watchdog-linger-');
        $this->pmssWriteRelativeFile($homeRoot.'/alice', '.config/systemd/user/custom.service', '[Unit]');
        $this->pmssWriteRelativeFile($lingerRoot, 'alice', '');
        $lookup = static function (): array { return ['uid' => 1001]; };
        $probe = static function (): array { return ['rc' => 0, 'stdout' => "active\n"]; };
        $status = null;

        $this->pmssWithEnv(['PMSS_CGROUP_MODE' => 'v1'], static function () use (&$status, $homeRoot, $lingerRoot, $probe, $lookup): void {
            $status = \pmssUserSystemdWatchdogRunUser('alice', $homeRoot, $lingerRoot, $probe, $lookup);
        });

        $this->assertSame('healthy', $status['state']);
        $this->assertTrue(is_file($homeRoot.'/alice/.systemd-user-status.json'));
        $this->assertSame(null, \pmssUserSystemdWatchdogRunUser('bad-name', $homeRoot, $lingerRoot, $probe, $lookup));
    }

    public function testRunUserSkipsAccountsWithoutIntentAndCgroupV2(): void
    {
        $homeRoot = $this->pmssMakeTempDir('user-systemd-watchdog-skip-homes-');
        $lingerRoot = $this->pmssMakeTempDir('user-systemd-watchdog-skip-linger-');
        $this->pmssWriteRelativeFile($homeRoot.'/alice', '.config/systemd/user/docker.service', '[Unit]');
        $this->pmssWriteRelativeFile($lingerRoot, 'alice', '');
        $probe = static function (): array { throw new \RuntimeException('probe must not run'); };
        $lookup = static function (): array { return ['uid' => 1001]; };

        $this->pmssWithEnv(['PMSS_CGROUP_MODE' => 'v1'], function () use ($homeRoot, $lingerRoot, $probe, $lookup): void {
            $this->assertSame(null, \pmssUserSystemdWatchdogRunUser('alice', $homeRoot, $lingerRoot, $probe, $lookup));
        });
        $this->pmssWriteRelativeFile($homeRoot.'/alice', '.config/systemd/user/custom.service', '[Unit]');
        $this->pmssWithEnv(['PMSS_CGROUP_MODE' => 'v2'], function () use ($homeRoot, $lingerRoot, $probe, $lookup): void {
            $this->assertSame(null, \pmssUserSystemdWatchdogRunUser('alice', $homeRoot, $lingerRoot, $probe, $lookup));
        });
    }

    public function testCronAndSkeletonUseTheExistingPerUserFlow(): void
    {
        $cron = $this->pmssReadRepoFile('scripts/cron/mediaStackInstancesCheck.php');
        $seed = $this->pmssReadRepoFile('etc/skel/.config/systemd/user/.gitkeep');

        $this->assertStringContainsString("require_once __DIR__.'/../lib/userSystemdWatchdog.php';", $cron);
        $this->assertOrderedStrings(['pmssMediaStackWatchdogRunUser(', 'pmssUserSystemdWatchdogRunUser('], $cron);
        $this->assertStringContainsString('standard systemd user-unit directory', $seed);
    }
}
