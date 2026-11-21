<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../cgroup/userSliceMemsw.php';

/**
 * Hermetic tests for the user.slice memory+swap bound of ADR 0298 (cron/cgroupUserSliceMemswApply.php).
 * Expected values come from the kernel rule (mm/memcontrol.c mem_cgroup_resize_max: the memory limit may not
 * exceed the memsw limit) and from the ADR, not from the helper's own output.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
class UserSliceMemswTest extends TestCase
{
    private const L = 6715080704;          // 6404 MiB, the ADR 0296 bound on a 7940 MiB guest
    private const UNLIMITED = 9223372036854771712; // what the kernel prints for "no limit"

    private function memsw(int $target, int $memory, int $memsw, int $anon = 0): array
    {
        return \pmssUserSliceMemswPlan($target, $memory, $memsw, $anon);
    }

    public function testTargetParse(): void
    {
        foreach ([['infinity', -1], ["6715080704\n", self::L], ['0', null], ['', null], ['[not set]', null], ['-5', null], ['1e9', null]] as [$text, $expected]) {
            $this->assertSame($expected, \pmssUserSliceMemswTarget($text), $text);
        }
    }

    public function testFirstRunWritesOnlyTheMemswLimit(): void
    {
        $this->assertSame([['memory.memsw.limit_in_bytes', (string) self::L]], $this->memsw(self::L, self::L, self::UNLIMITED)['writes']);
    }

    public function testNothingToDoWhenBothLimitsAlreadyMatch(): void
    {
        $this->assertSame([], $this->memsw(self::L, self::L, self::L)['writes']);
        $this->assertSame([], $this->memsw(-1, self::UNLIMITED, self::UNLIMITED)['writes']);
    }

    public function testRaisingWritesMemswBeforeMemory(): void
    {
        $this->assertSame(
            [['memory.memsw.limit_in_bytes', (string) (2 * self::L)], ['memory.limit_in_bytes', (string) (2 * self::L)]],
            $this->memsw(2 * self::L, self::L, self::L)['writes']
        );
    }

    public function testLoweringWritesMemoryBeforeMemsw(): void
    {
        $this->assertSame(
            [['memory.limit_in_bytes', (string) self::L], ['memory.memsw.limit_in_bytes', (string) self::L]],
            $this->memsw(self::L, 2 * self::L, 2 * self::L)['writes']
        );
    }

    public function testRollbackToInfinityRaisesMemswFirstAndUsesMinusOne(): void
    {
        $this->assertSame(
            [['memory.memsw.limit_in_bytes', '-1'], ['memory.limit_in_bytes', '-1']],
            $this->memsw(-1, self::L, self::L)['writes']
        );
    }

    public function testOnlyTheLimitThatDiffersIsWritten(): void
    {
        // memsw already at the target, memory still lower (systemd's own write refused earlier):
        $this->assertSame([['memory.limit_in_bytes', (string) self::L]], $this->memsw(self::L, 1073741824, self::L)['writes']);
    }

    public function testTenantsAboveTheBoundBlockOnlyALoweringOfMemsw(): void
    {
        $held = $this->memsw(self::L, self::L, self::UNLIMITED, self::L + 1);
        $this->assertSame([], $held['writes']);
        $this->assertTrue(is_string($held['note']) && $held['note'] !== '');
        // Raising is never blocked: the write cannot reclaim anything.
        $this->assertSame(2, count($this->memsw(2 * self::L, self::L, self::L, 3 * self::L)['writes']));
        // At or below the bound the memsw limit is written.
        $this->assertSame(1, count($this->memsw(self::L, self::L, self::UNLIMITED, self::L)['writes']));
    }

    public function testAnonymousPlusSwapFromMemoryStat(): void
    {
        $stat = "cache 5000\nrss 1\ntotal_cache 9000\ntotal_rss 4096\ntotal_rss_huge 0\ntotal_shmem 1024\ntotal_swap 2048\ntotal_mapped_file 77\n";
        $this->assertSame(4096 + 1024 + 2048, \pmssUserSliceMemswAnonPlusSwap($stat));
        $this->assertSame(0, \pmssUserSliceMemswAnonPlusSwap('garbage'));
    }

    public function testAnonymousPlusSwapSaturatesBeforeIntegerOverflow(): void
    {
        $max = (string) PHP_INT_MAX;
        // Include exact bounds, overflow at either addition and an oversized single field.
        foreach ([
            [0, '0', '0', '0'], [6, '1', '2', '3'],
            [PHP_INT_MAX, $max, '0', '0'],
            [PHP_INT_MAX, (string) (PHP_INT_MAX - 1), '1', '0'],
            [PHP_INT_MAX, (string) (PHP_INT_MAX - 2), '1', '1'],
            [PHP_INT_MAX, $max, '1', '0'],
            [PHP_INT_MAX, '1', $max, '0'],
            [PHP_INT_MAX, '0', '1', $max],
            [PHP_INT_MAX, (string) (PHP_INT_MAX - 1), '1', '1'],
            [PHP_INT_MAX, $max.'0', '1', '1'],
        ] as [$expected, $rss, $shmem, $swap]) {
            $stat = "total_rss $rss\ntotal_shmem $shmem\ntotal_swap $swap\n";
            $this->assertSame($expected, \pmssUserSliceMemswAnonPlusSwap($stat), $stat);
        }
    }

    public function testOverflowedUsageRetainsTheExistingLoweringRefusal(): void
    {
        $held = \pmssUserSliceMemswAnonPlusSwap('total_rss '.PHP_INT_MAX."\ntotal_swap 1\n");
        $plan = $this->memsw(self::L, self::L, self::UNLIMITED, $held);
        $this->pmssAssertArraySubsetSame(['writes' => [], 'note' => 'tenants hold '.PHP_INT_MAX
            .' bytes of anonymous memory plus swap, above the bound '.self::L.': memsw limit not written'], $plan);
        $this->assertSame($this->memsw(-1, self::L, self::L), $this->memsw(-1, self::L, self::L, $held));
        $this->assertSame($this->memsw(2 * self::L, self::L, self::L), $this->memsw(2 * self::L, self::L, self::L, $held));
    }

    public function testWiringContracts(): void
    {
        $this->pmssAssertRepoFileContainsString('etc/seedbox/config/root.cron',
            '/scripts/cron/cgroupUserSliceMemswApply.php >> /var/log/pmss/cgroupUserSliceMemswApply.log 2>&1');
        $this->pmssAssertRepoFileContainsString('etc/seedbox/config/template.logrotate.pmss', '/var/log/pmss/cgroupUserSliceMemswApply.log');
        $this->pmssAssertRepoFileContainsString('scripts/util/update-step2.php',
            "runStep('Bounding user.slice memory plus swap', '/scripts/cron/cgroupUserSliceMemswApply.php');");
        // Both boot and the five-minute self-heal run it.
        $cron = $this->pmssReadRepoFile('etc/seedbox/config/root.cron');
        $this->assertMatches('#^@reboot root +sleep [0-9]+; /scripts/cron/cgroupUserSliceMemswApply\.php#m', $cron);
        $this->assertMatches('#^\*/5 \* \* \* \* +root +sleep [0-9]+; /scripts/cron/cgroupUserSliceMemswApply\.php#m', $cron);
    }
}
