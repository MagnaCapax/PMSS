<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

/** Pins the user.slice aggregate memory bound (ADR 0296): value, both cgroup modes, idempotence, boundaries. */
class SystemdSliceAggregateMemoryTest extends TestCase
{
    private const TEMPLATE = 'etc/seedbox/config/template.cgroup.user-slice.aggregate.conf';

    /** Value table at the 1 GiB min_free floor: full RAM MiB => tenant bound MiB; 0 means no bound is rendered. */
    public function testBoundIsFullRamMinusReserveWithFloorAndWatermarkCap(): void
    {
        foreach ([
            24576 => 22784,   // 10% = 2458 is above the watermark reserve 1.25 x 1024 + 512 = 1792, which wins
            17920 => 16128,   // 10% = 1792 exactly: percentage and watermark agree
            15361 => 13824,   // 10% = 1536.1 rounds up to 1537, below the watermark reserve
            15360 => 13824,   // 10% = 1536 exactly: floor and percentage agree
            8192 => 6656,     // 10% = 819.2 is below the floor: the 1536 MiB reserve wins
            131072 => 129280, // 10% would be 13108: the watermark caps the reserve at 1792
            3072 => 1536,
            2560 => 1024,     // smallest rendered bound
            2559 => 0,        // below 1 GiB of tenant share
            1536 => 0,
            0 => 0,
            -4096 => 0,
        ] as $total => $expected) {
            $this->assertSame($expected, \pmssSystemdUserSliceAggregateMemoryMiB($total, 1024), 'full RAM '.$total);
        }
    }

    /** A larger min_free (big or fast-swap hosts) widens the reserve with the watermark it raises. */
    public function testReserveFollowsMinFree(): void
    {
        $this->assertSame(128000, \pmssSystemdUserSliceAggregateMemoryMiB(131072, 2048)); // 2560 + 512
        $this->assertSame(256512, \pmssSystemdUserSliceAggregateMemoryMiB(262144, 4096)); // 5120 + 512
        $this->assertSame(22784, \pmssSystemdUserSliceAggregateMemoryMiB(24576), 'default min_free is the 1 GiB floor');
    }

    /** The balloon counters give the RAM the balloon holds; min_free falls back to the floor. */
    public function testBalloonAndMinFreeReaders(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-agg-proc-');
        // 10288 MiB held: 2633728 more 4 KiB pages inflated than deflated (a 24 GiB guest at a 14288 MiB floor).
        $this->pmssWriteFile($dir.'/vmstat', "nr_free_pages 1\nballoon_inflate 1013168947\nballoon_deflate 1010535219\nballoon_migrate 7281779\n");
        $this->pmssWriteFile($dir.'/none', "nr_free_pages 1\n");
        $this->pmssWriteFile($dir.'/minfree', "2097152\n");
        $this->pmssWriteFile($dir.'/junk', "abc\n");
        $this->pmssWithEnv(['PMSS_BALLOON_HELD_MIB' => null, 'PMSS_MIN_FREE_KBYTES' => null], function () use ($dir): void {
            $this->assertSame(10288, \pmssBalloonHeldMiB($dir.'/vmstat'));
            $this->assertSame(0, \pmssBalloonHeldMiB($dir.'/none'), 'no balloon counters');
            $this->assertSame(0, \pmssBalloonHeldMiB($dir.'/missing'), 'unreadable vmstat');
            $this->assertSame(2048, \pmssMinFreeMiB($dir.'/minfree'));
            $this->assertSame(1024, \pmssMinFreeMiB($dir.'/junk'), 'unparsable min_free falls back to the floor');
            $this->assertSame(1024, \pmssMinFreeMiB($dir.'/missing'), 'unreadable min_free falls back to the floor');
        });
    }

    /** A guest rendered at its balloon floor still gets the bound of its full RAM. */
    public function testBalloonedGuestIsSizedFromFullRam(): void
    {
        $fixture = $this->pmssAggregateFixture('v1', 14288);
        $fixture['env']['PMSS_BALLOON_HELD_MIB'] = '10288';
        $messages = [];
        $this->pmssWithEnv($fixture['env'], function () use (&$messages): void {
            \pmssEnsureSystemdSlices($this->pmssMakeArrayLogger($messages));
        });
        $path = dirname($fixture['dropDir']).'/user.slice.d/15-pmss-aggregate.conf';
        $this->assertStringContainsString('MemoryMax=22784M', (string) file_get_contents($path));
        $this->assertTrue($this->pmssLogContains($messages, 'MemTotal=14288M, balloon=10288M, min_free=1024M'), json_encode($messages));
    }

    public function testShippedTemplateRendersSameDropinOnV1AndV2(): void
    {
        foreach (['v1', 'v2'] as $mode) {
            $run = $this->pmssAggregateRun($mode, 24576);
            $this->assertSame("[Slice]\nMemoryAccounting=yes\nMemoryMax=22784M\n", $run['aggregate'], $mode);
            $this->assertSame(0644, fileperms($run['aggregatePath']) & 0777, $mode.' mode');
            $this->assertTrue($this->pmssLogContains($run['log'], 'user.slice aggregate MemoryMax=22784M (MemTotal=24576M, balloon=0M, min_free=1024M, mode='.$mode.', ready=yes)'), $mode.' log: '.json_encode($run['log']));
        }
    }

    public function testSmallHostKeepsTheFloorReserve(): void
    {
        $run = $this->pmssAggregateRun('v1', 8192);
        $this->assertStringContainsString('MemoryMax=6656M', $run['aggregate']);
    }

    public function testPerUserDropinsAreUntouchedByTheAggregate(): void
    {
        foreach (['v1', 'v2'] as $mode) {
            $with = $this->pmssAggregateRun($mode, 24576);
            $without = $this->pmssAggregateRun($mode, 24576, false);
            $this->assertSame('', $without['aggregate'], $mode.' no aggregate without template');
            $this->assertSame($without['userSlice'], $with['userSlice'], $mode.' per-user drop-in bytes');
            // Per-user cap stays 125% of MemoryHigh (2457 MiB), far below the aggregate.
            $this->assertStringContainsString('MemoryMax=3071M', $with['userSlice'], $mode);
            $this->assertSame($without['root'], $with['root'], $mode.' root drop-in');
        }
    }

    public function testRerunIsIdempotentAndCreatesNoBackups(): void
    {
        $fixture = $this->pmssAggregateFixture('v1', 24576);
        $this->pmssSystemdSliceEnsure($fixture);
        $path = dirname($fixture['dropDir']).'/user.slice.d/15-pmss-aggregate.conf';
        clearstatcache(true, $path);
        $inode = fileinode($path);
        $bytes = (string) file_get_contents($path);
        $this->pmssSystemdSliceEnsure($fixture);
        clearstatcache(true, $path);
        $this->assertSame($inode, fileinode($path), 'unchanged drop-in must not be rewritten');
        $this->assertSame($bytes, (string) file_get_contents($path));
        $this->assertSame([$path], glob(dirname($path).'/*'), 'only the managed drop-in lives in user.slice.d');
    }

    public function testUpgradeAddsAggregateAndLeavesExistingDropinsByteIdentical(): void
    {
        $base = $this->pmssMakeTempDir('pmss-agg-up-');
        $drop = $base.'/user-.slice.d';
        $legacy = "[Slice]\nTasksMax=1000\n";
        $this->pmssWriteFile($drop.'/99-pmss.conf', $legacy);
        $fixture = $this->pmssAggregateFixture('v1', 24576, ['dropDir' => $drop]);
        $this->assertFalse(file_exists($base.'/user.slice.d'), 'pre-upgrade host has no user.slice.d');
        $this->pmssSystemdSliceEnsure($fixture);
        $this->assertStringContainsString('MemoryMax=22784M', (string) file_get_contents($base.'/user.slice.d/15-pmss-aggregate.conf'));
        // The legacy shadow is rewritten by the pre-existing TasksMax path only; no memory key enters it.
        $shadow = (string) file_get_contents($drop.'/99-pmss.conf');
        $this->assertFalse(strpos($shadow, 'Memory') !== false, 'shadow carries no memory key: '.$shadow);
    }

    public function testResizedGuestFollowsMemTotal(): void
    {
        $fixture = $this->pmssAggregateFixture('v2', 24576);
        $this->pmssSystemdSliceEnsure($fixture);
        $fixture['env']['PMSS_TOTAL_MEM_MIB'] = '32768';
        $this->pmssSystemdSliceEnsure($fixture);
        $path = dirname($fixture['dropDir']).'/user.slice.d/15-pmss-aggregate.conf';
        $this->assertStringContainsString('MemoryMax=30976M', (string) file_get_contents($path)); // 32768 - 1792
    }

    public function testTinyOrMissingTemplateNeverReplacesAWorkingDropin(): void
    {
        $fixture = $this->pmssAggregateFixture('v1', 24576);
        $this->pmssSystemdSliceEnsure($fixture);
        $path = dirname($fixture['dropDir']).'/user.slice.d/15-pmss-aggregate.conf';
        $good = (string) file_get_contents($path);

        $fixture['env']['PMSS_TOTAL_MEM_MIB'] = '2048';
        $messages = [];
        $this->pmssWithEnv($fixture['env'], function () use (&$messages): void {
            \pmssEnsureSystemdSlices($this->pmssMakeArrayLogger($messages));
        });
        $this->assertSame($good, (string) file_get_contents($path), 'tiny host keeps the previous bound');
        $this->assertTrue($this->pmssLogContains($messages, '[WARN] Skipping user.slice aggregate memory bound: MemTotal 2048M'), json_encode($messages));

        $fixture['env']['PMSS_TOTAL_MEM_MIB'] = '24576';
        unlink($fixture['cfgDir'].'/template.cgroup.user-slice.aggregate.conf');
        $messages = [];
        $this->pmssWithEnv($fixture['env'], function () use (&$messages): void {
            \pmssEnsureSystemdSlices($this->pmssMakeArrayLogger($messages));
        });
        $this->assertSame($good, (string) file_get_contents($path), 'missing template keeps the previous bound');
        $this->assertTrue($this->pmssLogContains($messages, '[WARN] Unable to read user.slice aggregate template'), json_encode($messages));
    }

    public function testSymlinkedParentSliceDirectoryIsRefused(): void
    {
        $base = $this->pmssMakeTempDir('pmss-agg-link-');
        $elsewhere = $this->pmssMakeTempDir('pmss-agg-elsewhere-');
        symlink($elsewhere, $base.'/user.slice.d');
        $fixture = $this->pmssAggregateFixture('v1', 24576, ['dropDir' => $base.'/user-.slice.d']);
        $this->pmssSystemdSliceEnsure($fixture);
        $this->assertSame([], glob($elsewhere.'/*'), 'nothing written through the symlink');
    }

    public function testRollbackIsRemovingTheDropinAndNothingElse(): void
    {
        $fixture = $this->pmssAggregateFixture('v1', 24576);
        $this->pmssSystemdSliceEnsure($fixture);
        $userSlice = (string) file_get_contents($fixture['dropDir'].'/15-pmss.conf');
        $aggregate = dirname($fixture['dropDir']).'/user.slice.d/15-pmss-aggregate.conf';
        unlink($aggregate);
        $this->assertSame($userSlice, (string) file_get_contents($fixture['dropDir'].'/15-pmss.conf'), 'per-user drop-in unaffected by removal');
        $this->assertFalse(file_exists($aggregate));
    }

    /** @return array{aggregate:string,aggregatePath:string,userSlice:string,root:string,log:array<int,string>} */
    private function pmssAggregateRun(string $mode, int $totalMiB, bool $withTemplate = true): array
    {
        $fixture = $this->pmssAggregateFixture($mode, $totalMiB, ['aggregateTemplate' => $withTemplate]);
        $messages = [];
        $this->pmssWithEnv($fixture['env'], function () use (&$messages): void {
            \pmssEnsureSystemdSlices($this->pmssMakeArrayLogger($messages));
        });
        $base = dirname($fixture['dropDir']);
        $path = $base.'/user.slice.d/15-pmss-aggregate.conf';
        return [
            'aggregate' => is_file($path) ? (string) file_get_contents($path) : '',
            'aggregatePath' => $path,
            'userSlice' => (string) file_get_contents($fixture['dropDir'].'/15-pmss.conf'),
            'root' => (string) file_get_contents($base.'/user-0.slice.d/99-zz-pmss-unlimited.conf'),
            'log' => $messages,
        ];
    }

    /**
     * Fixture with per-user v1/v2 templates and, by default, the shipped aggregate template.
     *
     * @param array<string,mixed> $options dropDir, aggregateTemplate
     * @return array{cfgDir:string,dropDir:string,env:array<string,string>}
     */
    private function pmssAggregateFixture(string $mode, int $totalMiB, array $options = []): array
    {
        $base = $this->pmssMakeTempDir('pmss-agg-');
        $fixture = $this->pmssSystemdSliceFixturePrepare([
            'dropDir' => (string) ($options['dropDir'] ?? $base.'/user-.slice.d'),
            'mode' => $mode,
            'v1Template' => $this->pmssSystemdSliceTasksTemplate(['MemoryHigh=%%USER_CGROUP_MEMORY_HIGH%%M', 'MemoryMax=%%USER_CGROUP_MEMORY_MAX%%M']),
            'v2Template' => $this->pmssSystemdSliceTasksTemplate(['MemoryHigh=%%USER_CGROUP_MEMORY_HIGH%%M', 'MemoryMax=%%USER_CGROUP_MEMORY_MAX%%M']),
            'totalMemMiB' => $totalMiB,
            'env' => ['PMSS_SYSTEMD_USER_AT_SERVICE_DIR' => dirname((string) ($options['dropDir'] ?? $base.'/user-.slice.d')).'/user@.service.d'],
        ]);
        if (($options['aggregateTemplate'] ?? true) === true) {
            $this->pmssWriteFile($fixture['cfgDir'].'/template.cgroup.user-slice.aggregate.conf', $this->pmssReadRepoFile(self::TEMPLATE));
        }
        return $fixture;
    }

    /** @param array<int,string> $log */
    private function pmssLogContains(array $log, string $needle): bool
    {
        foreach ($log as $line) {
            if (strpos($line, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}
