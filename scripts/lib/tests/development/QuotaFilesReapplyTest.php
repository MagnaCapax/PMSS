<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/quotaFilesReapply.php';

/** Re-applying a changed per-host file quota ratio to existing accounts (ADR 0342). */
class QuotaFilesReapplyTest extends TestCase
{
    private $configDir;
    private $stateDir;

    public function setUp(): void
    {
        $this->configDir = $this->pmssMakeTempDir('pmss-quota-reapply-config-');
        $this->stateDir = $this->pmssMakeTempDir('pmss-quota-reapply-state-');
        $this->pmssEnsureDir($this->configDir.'/users');
        $this->pmssTrackEnvOverrides(['PMSS_CONFIG_DIR' => $this->configDir, 'PMSS_STATE_DIR' => $this->stateDir], true);
    }

    private function ratioFile(?string $content): void
    {
        @unlink($this->configDir.'/quotaFilesPerGiB');
        if ($content !== null) {
            $this->pmssWriteFile($this->configDir.'/quotaFilesPerGiB', $content);
        }
    }

    /** Run the re-apply step with a recording apply stub; returns [exit code, applied users, log text]. */
    private function reapply(array $users, array $inUse = [], array $failing = []): array
    {
        $applied = [];
        ob_start();
        $rc = \pmssQuotaFilesReapplyRun($users, static function (array $user) use (&$applied, $failing): bool {
            $applied[] = $user['name'].':'.$user['quota'];
            return !in_array($user['name'], $failing, true);
        }, static function (array $names) use ($inUse): array {
            return $inUse;
        });
        return [$rc, $applied, (string) ob_get_clean()];
    }

    private function storedQuota(string $user, ?int $quota): void
    {
        $this->pmssWriteFile($this->configDir.'/users/'.$user.'.json', json_encode(['ramMiB' => 512, 'rtorrentPort' => 0, 'quotaBurst' => 6] + ($quota === null ? [] : ['quota' => $quota])));
    }

    private function recorded(): ?string
    {
        $content = @file_get_contents($this->stateDir.'/quotaFilesPerGiBApplied');
        return $content === false ? null : $content;
    }

    public function testReapplyDoesNothingWithoutFileAndRecord(): void
    {
        $this->storedQuota('fixtur01', 100);
        [$rc, $applied, $log] = $this->reapply(['fixtur01']);
        $this->assertSame([0, [], ''], [$rc, $applied, $log]);
        $this->assertSame(null, $this->recorded());
    }

    public function testReapplyAppliesStoredQuotasOnceAndRecordsTheRatio(): void
    {
        $this->storedQuota('fixtur01', 100);
        $this->storedQuota('fixtur02', null);
        $this->ratioFile('2000');
        [$rc, $applied, $log] = $this->reapply(['fixtur01', 'fixtur02', 'fixtur03']);
        $this->assertSame(0, $rc);
        $this->assertSame(['fixtur01:100'], $applied);
        $this->assertStringContainsString('[SKIP] File quota ratio for fixtur02: no stored quota', $log);
        $this->assertStringContainsString('[SKIP] File quota ratio for fixtur03: no stored quota', $log);
        $this->assertSame("2000\n", $this->recorded());
        // The second run sees the recorded ratio and changes nothing.
        $this->assertSame([0, [], ''], $this->reapply(['fixtur01', 'fixtur02', 'fixtur03']));
    }

    public function testLoweredRatioIsReappliedAndOverLimitAccountsAreLogged(): void
    {
        $this->storedQuota('fixtur01', 20);
        $this->storedQuota('fixtur02', 20);
        $this->pmssWriteFile($this->stateDir.'/quotaFilesPerGiBApplied', "4000\n");
        $this->ratioFile('500');
        [$rc, $applied, $log] = $this->reapply(['fixtur01', 'fixtur02'], ['fixtur01' => 40000, 'fixtur02' => 9000]);
        $this->assertSame([0, ['fixtur01:20', 'fixtur02:20']], [$rc, $applied]);
        $this->assertStringContainsString('fixtur01: holds 40000 files, above the new soft limit 15000', $log);
        $this->assertStringNotContainsString('fixtur02: holds', $log);
        $this->assertSame("500\n", $this->recorded());
    }

    public function testRaisedRatioDoesNotLogOverLimitAccounts(): void
    {
        $this->storedQuota('fixtur01', 20);
        $this->ratioFile('4000');
        [, , $log] = $this->reapply(['fixtur01'], ['fixtur01' => 90000]);
        $this->assertStringNotContainsString('holds', $log);
    }

    public function testFailedApplyKeepsTheRecordSoTheNextUpdateRetries(): void
    {
        $this->storedQuota('fixtur01', 20);
        $this->storedQuota('fixtur02', 20);
        $this->ratioFile('2000');
        [$rc, $applied, $log] = $this->reapply(['fixtur01', 'fixtur02'], [], ['fixtur01']);
        $this->assertSame(1, $rc);
        $this->assertSame(['fixtur01:20', 'fixtur02:20'], $applied);
        $this->assertStringContainsString('fixtur01: applying the quota failed', $log);
        $this->assertSame(null, $this->recorded());
    }

    public function testMalformedRecordCountsAsTheHistoricalRatio(): void
    {
        $this->storedQuota('fixtur01', 20);
        $this->pmssWriteFile($this->stateDir.'/quotaFilesPerGiBApplied', 'garbage');
        $this->ratioFile(null);
        $this->assertSame([0, [], ''], $this->reapply(['fixtur01']));
        $this->ratioFile('2000');
        $this->assertSame(['fixtur01:20'], $this->reapply(['fixtur01'])[1]);
    }

    public function testDryRunRecordsNothing(): void
    {
        $this->storedQuota('fixtur01', 20);
        $this->ratioFile('2000');
        $this->pmssTrackEnvOverrides(['PMSS_DRY_RUN' => '1'], true);
        $this->assertSame(0, $this->reapply(['fixtur01'])[0]);
        $this->assertSame(null, $this->recorded());
    }
}
