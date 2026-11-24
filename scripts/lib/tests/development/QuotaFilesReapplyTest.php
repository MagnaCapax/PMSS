<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/quotaFilesReapply.php';

/** Re-applying a changed per-host file quota ratio to existing accounts (ADR 0342): file limits only, block limits stay live. */
class QuotaFilesReapplyTest extends TestCase
{
    /** 1000 GiB and its 1.25 hard limit in KiB: what a quota raised outside userConfig.php left in the kernel. */
    private const LIVE_SOFT_KIB = 1048576000;
    private const LIVE_HARD_KIB = 1310720000;

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

    /** One live repquota row as pmssQuotaLiveRows() returns it. */
    private function liveRow(int $softKiB = self::LIVE_SOFT_KIB, int $hardKiB = self::LIVE_HARD_KIB, int $files = 100): array
    {
        return ['blockSoft' => $softKiB, 'blockHard' => $hardKiB, 'files' => $files];
    }

    /**
     * Run the re-apply step with a recording apply stub and a stubbed live read.
     * Returns [exit code, applied accounts as name:GiB:softKiB:hardKiB, log text].
     */
    private function reapply(array $users, array $live = [], array $failing = []): array
    {
        $applied = [];
        ob_start();
        $rc = \pmssQuotaFilesReapplyRun($users, static function (array $user) use (&$applied, $failing): bool {
            $applied[] = $user['name'].':'.$user['quota'].':'.$user['blockSoftKiB'].':'.$user['blockHardKiB'];
            return !in_array($user['name'], $failing, true);
        }, static function (array $names) use ($live): array {
            return $live;
        });
        return [$rc, $applied, (string) ob_get_clean()];
    }

    /** The account's stored json, as a stale quota of 900 GiB (what userConfig.php last wrote). */
    private function storedQuota(string $user, ?int $quota): void
    {
        $this->pmssWriteFile($this->configDir.'/users/'.$user.'.json', json_encode(['ramMiB' => 512, 'rtorrentPort' => 0, 'quotaBurst' => 6] + ($quota === null ? [] : ['quota' => $quota])));
    }

    private function recorded(): ?string
    {
        $content = @file_get_contents($this->stateDir.'/quotaFilesPerGiBApplied');
        return $content === false ? null : $content;
    }

    /** A fake repquota first on PATH, so the real live reader runs without touching a quota filesystem. */
    private function fakeRepquota(string $body, int $rc = 0): void
    {
        $bin = $this->pmssMakeTempDir('pmss-quota-reapply-bin-');
        $this->pmssWriteExecutableFile($bin.'/repquota', "#!/bin/bash\ncat <<'EOT'\n{$body}\nEOT\nexit {$rc}\n");
        $this->pmssTrackEnvOverrides(['PATH' => $bin.':'.(string) getenv('PATH')], true);
    }

    public function testReapplyDoesNothingWithoutFileAndRecord(): void
    {
        $this->storedQuota('fixtur01', 100);
        [$rc, $applied, $log] = $this->reapply(['fixtur01'], ['fixtur01' => $this->liveRow()]);
        $this->assertSame([0, [], ''], [$rc, $applied, $log]);
        $this->assertSame(null, $this->recorded());
    }

    public function testReapplyPassesTheLiveBlockLimitsAndNeverTheStaleStoredQuota(): void
    {
        // The stored quota (900 GiB) is older than the kernel quota (1000 GiB, raised outside userConfig.php).
        $this->storedQuota('fixtur01', 900);
        $this->ratioFile('2000');
        [$rc, $applied, $log] = $this->reapply(['fixtur01'], ['fixtur01' => $this->liveRow()]);
        $this->assertSame(0, $rc);
        $this->assertSame(['fixtur01:1000:'.self::LIVE_SOFT_KIB.':'.self::LIVE_HARD_KIB], $applied);
        $this->assertStringNotContainsString('900', $log);
        $this->assertSame("2000\n", $this->recorded());
        // The second run sees the recorded ratio and changes nothing.
        $this->assertSame([0, [], ''], $this->reapply(['fixtur01'], ['fixtur01' => $this->liveRow()]));
    }

    public function testRealApplyWritesTheLiveBlockLimitsBackAndOnlyNewFileLimits(): void
    {
        $this->pmssMakeTrackedHomeRoot('pmss-quota-reapply-home-');
        $this->pmssTrackEnvOverrides(['PMSS_DRY_RUN' => '1'], true);
        $this->storedQuota('fixtur01', 900);
        $this->ratioFile('2000');
        $this->pmssResetRuntimeProfile();
        ob_start();
        $rc = \pmssQuotaFilesReapplyRun(['fixtur01'], null, static function (array $names): array {
            return ['fixtur01' => ['blockSoft' => 1048576000, 'blockHard' => 1310720000, 'files' => 100]];
        });
        ob_end_clean();
        $this->assertSame(0, $rc);
        // Block limits are the live 1000 GiB pair; the file limits are 2000 per live GiB, hard 1.25 times soft.
        $this->assertStringContainsString("setquota 'fixtur01' 1048576000 1310720000 2000000 2500000 -a", implode("\n", $this->pmssProfileCommands()));
        // Without live limits (first-run path) the blocks still derive from `quota`; files use the same ratio.
        $this->pmssResetRuntimeProfile();
        $this->assertTrue(\userApplyDiskQuota(['name' => 'fixtur01', 'quota' => 900]) === true);
        $this->assertStringContainsString("setquota 'fixtur01' 943718400 1179648000 1800000 2250000 -a", implode("\n", $this->pmssProfileCommands()));
    }

    public function testAccountWithoutLiveLimitsIsLeftUntouchedAndRetried(): void
    {
        $this->storedQuota('fixtur01', 900);
        $this->storedQuota('fixtur02', 900);
        $this->ratioFile('2000');
        // fixtur02 has a stored quota but the live read gave nothing for it: the json must not stand in.
        [$rc, $applied, $log] = $this->reapply(['fixtur01', 'fixtur02'], ['fixtur01' => $this->liveRow()]);
        $this->assertSame(1, $rc);
        $this->assertSame(['fixtur01:1000:'.self::LIVE_SOFT_KIB.':'.self::LIVE_HARD_KIB], $applied);
        $this->assertStringContainsString('fixtur02: live block limits could not be read; account left unchanged', $log);
        $this->assertStringContainsString('not recorded: 1 account(s) failed', $log);
        $this->assertSame(null, $this->recorded());
    }

    public function testNoLiveReportAtAllChangesNoAccountAndRecordsNothing(): void
    {
        $this->storedQuota('fixtur01', 900);
        $this->ratioFile('2000');
        [$rc, $applied, $log] = $this->reapply(['fixtur01'], []);
        $this->assertSame([1, []], [$rc, $applied]);
        $this->assertStringContainsString('fixtur01: live block limits could not be read', $log);
        $this->assertSame(null, $this->recorded());
    }

    public function testFailingRepquotaGivesNoLiveRowsAndNoApply(): void
    {
        $account = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
        $this->assertTrue(is_array($account), 'Expected a passwd entry for the test user');
        $this->fakeRepquota("*** Report for user quotas on device /dev/fixture\n#{$account['uid']} -- 1 2 3 4 5 6", 2);
        $this->assertSame([], \pmssQuotaLiveRows([$account['name']]));
        // An unrecognized report (no header) is never read as limits either.
        $this->fakeRepquota("#{$account['uid']} -- 1 2 3 4 5 6");
        $this->assertSame([], \pmssQuotaLiveRows([$account['name']]));
    }

    public function testLiveReaderParsesTheKernelRowOfTheAccount(): void
    {
        $account = posix_getpwuid(posix_geteuid());
        $this->fakeRepquota(
            "*** Report for user quotas on device /dev/fixture\nBlock grace time: 7days; Inode grace time: 7days\n"
            ."                        Block limits                File limits\n"
            ."User            used    soft    hard  grace    used  soft  hard  grace\n"
            ."----------------------------------------------------------------------\n"
            ."#{$account['uid']}      --  5000 1048576000 1310720000          678 2000000 2500000\n"
            ."#4000000000     --  1 1 1 1 1 1"
        );
        $this->assertSame(
            [$account['name'] => ['blockSoft' => 1048576000, 'blockHard' => 1310720000, 'files' => 678]],
            \pmssQuotaLiveRows([$account['name'], 'fixtur99'])
        );
    }

    public function testUnlimitedLiveBlockSoftLimitIsSkippedNotRewritten(): void
    {
        $this->storedQuota('fixtur01', 100);
        $this->ratioFile('2000');
        [$rc, $applied, $log] = $this->reapply(['fixtur01'], ['fixtur01' => $this->liveRow(0, 0)]);
        $this->assertSame([0, []], [$rc, $applied]);
        $this->assertStringContainsString('[SKIP] File quota ratio for fixtur01: no live block soft limit', $log);
        $this->assertSame("2000\n", $this->recorded());
    }

    public function testLoweredRatioIsReappliedAndOverLimitAccountsAreLogged(): void
    {
        $twentyGiB = 20 * 1024 * 1024;
        $this->pmssWriteFile($this->stateDir.'/quotaFilesPerGiBApplied', "4000\n");
        $this->ratioFile('500');
        [$rc, $applied, $log] = $this->reapply(['fixtur01', 'fixtur02'], [
            'fixtur01' => $this->liveRow($twentyGiB, 26214400, 40000),
            'fixtur02' => $this->liveRow($twentyGiB, 26214400, 9000),
        ]);
        $this->assertSame([0, ['fixtur01:20:'.$twentyGiB.':26214400', 'fixtur02:20:'.$twentyGiB.':26214400']], [$rc, $applied]);
        $this->assertStringContainsString('fixtur01: holds 40000 files, above the new soft limit 15000', $log);
        $this->assertStringNotContainsString('fixtur02: holds', $log);
        $this->assertSame("500\n", $this->recorded());
    }

    public function testRaisedRatioDoesNotLogOverLimitAccounts(): void
    {
        $this->ratioFile('4000');
        [, , $log] = $this->reapply(['fixtur01'], ['fixtur01' => $this->liveRow(20 * 1024 * 1024, 26214400, 90000)]);
        $this->assertStringNotContainsString('holds', $log);
    }

    public function testFailedApplyKeepsTheRecordSoTheNextUpdateRetries(): void
    {
        $this->ratioFile('2000');
        $live = ['fixtur01' => $this->liveRow(), 'fixtur02' => $this->liveRow()];
        [$rc, $applied, $log] = $this->reapply(['fixtur01', 'fixtur02'], $live, ['fixtur01']);
        $this->assertSame(1, $rc);
        $this->assertSame(2, count($applied));
        $this->assertStringContainsString('fixtur01: applying the file limits failed', $log);
        $this->assertSame(null, $this->recorded());
    }

    public function testMalformedRecordCountsAsTheHistoricalRatio(): void
    {
        $live = ['fixtur01' => $this->liveRow(20 * 1024 * 1024, 26214400)];
        $this->pmssWriteFile($this->stateDir.'/quotaFilesPerGiBApplied', 'garbage');
        $this->ratioFile(null);
        $this->assertSame([0, [], ''], $this->reapply(['fixtur01'], $live));
        $this->ratioFile('2000');
        $this->assertSame(['fixtur01:20:20971520:26214400'], $this->reapply(['fixtur01'], $live)[1]);
    }

    public function testDryRunRecordsNothing(): void
    {
        $this->ratioFile('2000');
        $this->pmssTrackEnvOverrides(['PMSS_DRY_RUN' => '1'], true);
        $this->assertSame(0, $this->reapply(['fixtur01'], ['fixtur01' => $this->liveRow()])[0]);
        $this->assertSame(null, $this->recorded());
    }

    public function testNoSourceReadsTheStoredJsonQuota(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/user/quotaFilesReapply.php', ['repquota -u -n /home'], ['UserConfigStore', "['quota']"]);
    }
}
