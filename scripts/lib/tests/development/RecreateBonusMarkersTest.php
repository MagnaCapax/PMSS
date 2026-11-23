<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/recreateBonusMarkers.php';

/** A rebuilt home shows the bonus root holds: display copies come from the root store only (ADR 0213). */
class RecreateBonusMarkersTest extends TestCase
{
    /** Store with one record, plus an empty home and a backup that holds a tenant-edited .bonus. */
    private function fixture(array $record): array
    {
        $base = $this->pmssMakeTempDir('pmss-recreate-bonus-');
        $this->pmssEnsureDir($base.'/config/users');
        $this->pmssEnsureDir($base.'/home');
        $this->pmssEnsureDir($base.'/backup');
        file_put_contents($base.'/config/users/fixtur01.json', json_encode($record));
        file_put_contents($base.'/backup/.bonus', "300\n");
        file_put_contents($base.'/backup/.bonusTraffic', "999999\n");
        return [new \UserConfigStore($base.'/config'), $base.'/home', $base];
    }

    /** Recording writer with the pmssWriteManagedFile signature; it also writes into the temp home. */
    private function writer(array &$calls, bool $ok = true): callable
    {
        return static function (string $path, string $content, string $owner, ?string $group, int $mode) use (&$calls, $ok): bool {
            $calls[] = [basename($path), $content, $owner, $group, $mode];
            return $ok && file_put_contents($path, $content) !== false;
        };
    }

    public function testStoreValuesAreRenderedWithRegistryOwnerGroupAndMode(): void
    {
        [$store, $home] = $this->fixture(['ramMiB' => 512, 'quota' => 5, 'bonusPercent' => 40, 'bonusTrafficGiB' => 25]);
        $calls = [];

        $result = \pmssRecreateBonusMarkersRender('fixtur01', $home, $store, $this->writer($calls));

        $this->assertSame(['.bonus' => true, '.bonusTraffic' => true], $result);
        $this->assertSame([
            ['.bonus', '40', 'root', 'fixtur01', 0640],
            ['.bonusTraffic', '25', 'root', 'fixtur01', 0640],
        ], $calls);
        $this->assertSame('40', file_get_contents($home.'/.bonus'));
        $this->assertSame('25', file_get_contents($home.'/.bonusTraffic'));
    }

    public function testBackupValuesEditedByTheAccountAreNeverRead(): void
    {
        [$store, $home, $base] = $this->fixture(['ramMiB' => 512, 'quota' => 5, 'bonusPercent' => 40]);
        $calls = [];

        \pmssRecreateBonusMarkersRender('fixtur01', $home, $store, $this->writer($calls));

        // The backup holds 300 and 999999; the store holds 40 and nothing for traffic.
        $this->assertSame('40', file_get_contents($home.'/.bonus'));
        $this->assertFalse(file_exists($home.'/.bonusTraffic'), 'a backup-only value must not be carried over');
        $this->assertSame("300\n", file_get_contents($base.'/backup/.bonus'));
    }

    /** The issue asked that a symlinked or oversized backup marker be skipped; it is never opened at all. */
    public function testSymlinkedOrOversizedBackupMarkersAreNeverFollowedOrRead(): void
    {
        [$store, $home, $base] = $this->fixture(['ramMiB' => 512, 'quota' => 5, 'bonusPercent' => 40]);
        unlink($base.'/backup/.bonus');
        file_put_contents($base.'/outside-secret', "250\n");
        $this->assertTrue(symlink($base.'/outside-secret', $base.'/backup/.bonus'));
        file_put_contents($base.'/backup/.bonusTraffic', str_repeat('9', 4096));
        $calls = [];

        \pmssRecreateBonusMarkersRender('fixtur01', $home, $store, $this->writer($calls));

        $this->assertSame('40', file_get_contents($home.'/.bonus'));
        $this->assertFalse(file_exists($home.'/.bonusTraffic'));
        $this->assertSame("250\n", file_get_contents($base.'/outside-secret'));
    }

    /** An upgraded account whose bonus exists only as a home file (before the store held it) gets no invented value. */
    public function testAccountWithBonusOnlyInTheBackupGetsNoMarker(): void
    {
        [$store, $home] = $this->fixture(['ramMiB' => 512, 'quota' => 5]);
        $calls = [];
        $this->assertSame([], \pmssRecreateBonusMarkersRender('fixtur01', $home, $store, $this->writer($calls)));
        $this->assertSame(['.', '..'], scandir($home));
    }

    /** Rendering for one account reads and writes that account only. */
    public function testAnotherAccountsStoredBonusIsNeverRendered(): void
    {
        [$store, $home, $base] = $this->fixture(['ramMiB' => 512, 'quota' => 5, 'bonusPercent' => 40]);
        file_put_contents($base.'/config/users/fixtur02.json', json_encode(['ramMiB' => 512, 'quota' => 5, 'bonusPercent' => 200, 'bonusTrafficGiB' => 90]));
        $calls = [];

        \pmssRecreateBonusMarkersRender('fixtur01', $home, $store, $this->writer($calls));

        $this->assertSame('40', file_get_contents($home.'/.bonus'));
        $this->assertFalse(file_exists($home.'/.bonusTraffic'));
        $this->assertSame(1, count($calls));
    }

    /** A second rebuild pass over the same store leaves the same files. */
    public function testRenderingTwiceLeavesTheSameFiles(): void
    {
        [$store, $home] = $this->fixture(['ramMiB' => 512, 'quota' => 5, 'bonusPercent' => 40, 'bonusTrafficGiB' => 25]);
        $calls = [];
        $first = \pmssRecreateBonusMarkersRender('fixtur01', $home, $store, $this->writer($calls));
        $second = \pmssRecreateBonusMarkersRender('fixtur01', $home, $store, $this->writer($calls));
        $this->assertSame($first, $second);
        $this->assertSame('40', file_get_contents($home.'/.bonus'));
        $this->assertSame('25', file_get_contents($home.'/.bonusTraffic'));
        $this->assertSame(['.', '..', '.bonus', '.bonusTraffic'], scandir($home));
    }

    public function testNoStoreRecordOrNoBonusWritesNothing(): void
    {
        [$store, $home] = $this->fixture(['ramMiB' => 512, 'quota' => 5]);
        $calls = [];
        $this->assertSame([], \pmssRecreateBonusMarkersRender('fixtur01', $home, $store, $this->writer($calls)));
        $this->assertSame([], \pmssRecreateBonusMarkersRender('fixtur99', $home, $store, $this->writer($calls)));
        $this->assertSame([], $calls);
        $this->assertSame(['.', '..'], scandir($home));
    }

    public function testZeroNegativeOverMaxAndNonIntegerStoreValuesAreSkipped(): void
    {
        foreach ([
            ['bonusPercent' => 0, 'bonusTrafficGiB' => 0],
            ['bonusPercent' => 301],
            ['bonusPercent' => -5, 'bonusTrafficGiB' => -1],
            ['bonusPercent' => 'abc', 'bonusTrafficGiB' => null],
        ] as $values) {
            [$store, $home] = $this->fixture(array_merge(['ramMiB' => 512, 'quota' => 5], $values));
            $calls = [];
            $this->assertSame([], \pmssRecreateBonusMarkersRender('fixtur01', $home, $store, $this->writer($calls)), json_encode($values));
            $this->assertSame([], $calls, json_encode($values));
        }
    }

    public function testMaximumPolicyValueIsRendered(): void
    {
        [$store, $home] = $this->fixture(['ramMiB' => 512, 'quota' => 5, 'bonusPercent' => 300]);
        $calls = [];
        $this->assertSame(['.bonus' => true], \pmssRecreateBonusMarkersRender('fixtur01', $home, $store, $this->writer($calls)));
        $this->assertSame('300', file_get_contents($home.'/.bonus'));
    }

    public function testFailedWriteIsReportedPerMarkerAndDoesNotStopTheOthers(): void
    {
        [$store, $home] = $this->fixture(['ramMiB' => 512, 'quota' => 5, 'bonusPercent' => 40, 'bonusTrafficGiB' => 25]);
        $calls = [];
        $result = \pmssRecreateBonusMarkersRender('fixtur01', $home, $store, $this->writer($calls, false));
        $this->assertSame(['.bonus' => false, '.bonusTraffic' => false], $result);
        $this->assertSame(2, count($calls));
    }

    public function testOnlyStoreBackedBonusMarkersAreRendered(): void
    {
        // Billing markers have store keys too but are restored by their own path; display-only markers have none.
        [$store, $home] = $this->fixture(['ramMiB' => 512, 'quota' => 5, 'bonusPercent' => 40,
            'billingServiceId' => 123, 'billingClientId' => 456]);
        $calls = [];
        $result = \pmssRecreateBonusMarkersRender('fixtur01', $home, $store, $this->writer($calls));
        $this->assertSame(['.bonus'], array_keys($result));
    }

    public function testRebuildRendersAfterTheRestoreAndBeforeTheHomeHandoff(): void
    {
        $this->assertOrderedStrings([
            "require_once __DIR__.'/lib/user/recreateBonusMarkers.php';",
            'pmssRecreateRestoreHome($homeDir, $backupDir',
            'pmssRecreateBonusMarkersRender($userName, $homeDir)',
            "// Only the top-level handoff",
        ], $this->pmssReadRepoFile('scripts/recreateUser.php'));
    }
}
