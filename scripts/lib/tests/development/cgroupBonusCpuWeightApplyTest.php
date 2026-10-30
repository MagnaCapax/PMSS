<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../cgroup/bonusCpuWeight.php';

/**
 * Hermetic tests for the Free Bonus Disk -> CPU priority helpers used by
 * /scripts/cron/cgroupBonusCpuWeightApply.php, and for the shared ~/.bonus reader.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
class CgroupBonusCpuWeightApplyTest extends TestCase
{
    public function testBonusRaisesWeightAndNeverGoesBelowBase(): void
    {
        foreach ([
            [200, 0, 200],
            [200, 50, 300],
            [100, 300, 400],
            [5000, 300, 10000],   // capped at systemd's maximum
            [10000, 100, 10000],
            [700, -20, 700],      // a negative percent is treated as 0
            [0, 100, 2],          // base floors at 1 before the bonus
            [333, 33, 443],       // round(333 * 1.33) = 442.89 -> 443
        ] as [$base, $pct, $expected]) {
            $actual = \pmssCpuApplyBonusWeight($base, $pct);
            $this->assertSame($expected, $actual, "base=$base pct=$pct");
            $this->assertTrue($actual >= max(1, min($base, \PMSS_CPU_WEIGHT_MAX)), "below base for base=$base pct=$pct");
        }
    }

    public function testBaseWeightFollowsUserConfig(): void
    {
        $this->assertSame(200, \pmssBonusCpuBaseWeight(['CPUWeight' => 200, 'ramMiB' => 4000]));
        $this->assertSame(10000, \pmssBonusCpuBaseWeight(['CPUWeight' => 99999]));
        // CPUWeight 0 or absent: userConfig derives it from the RAM target (floored at 250 MiB).
        $this->assertSame(\PMSS\Cgroup\Manager::calculateWeightFromMemory(4000), \pmssBonusCpuBaseWeight(['CPUWeight' => 0, 'ramMiB' => 4000]));
        $this->assertSame(\PMSS\Cgroup\Manager::calculateWeightFromMemory(250), \pmssBonusCpuBaseWeight(['ramMiB' => 100]));
        $this->assertSame(null, \pmssBonusCpuBaseWeight(['ramMiB' => 0]));
        $this->assertSame(null, \pmssBonusCpuBaseWeight([]));
    }

    public function testMarkerIsReadWithTheSharedValueClamp(): void
    {
        $root = $this->pmssMakeTempDir('bonus-cpu-home');
        $home = $root.'/alice';
        mkdir($home, 0770);
        file_put_contents($home.'/.bonus', "50\n");
        $this->assertSame(50, \pmssUserBonusPercentRead('alice', $root));

        // A padded marker (the tenant owns the home) is clamped to the 300% policy maximum.
        file_put_contents($home.'/.bonus', "999999\n");
        $this->assertSame(\PMSS_BFQ_FALLBACK_MAX_BONUS_PERCENT, \pmssUserBonusPercentRead('alice', $root));

        // Garbage reads as 0; so does a negative number.
        file_put_contents($home.'/.bonus', "lots\n");
        $this->assertSame(0, \pmssUserBonusPercentRead('alice', $root));
        file_put_contents($home.'/.bonus', "-40\n");
        $this->assertSame(0, \pmssUserBonusPercentRead('alice', $root));

        // A link to a file elsewhere is refused before it is opened.
        unlink($home.'/.bonus');
        file_put_contents($root.'/target', "300\n");
        symlink($root.'/target', $home.'/.bonus');
        $this->assertSame(0, \pmssUserBonusPercentRead('alice', $root));

        // A bulky file is refused.
        unlink($home.'/.bonus');
        file_put_contents($home.'/.bonus', str_repeat('1', 100));
        $this->assertSame(0, \pmssUserBonusPercentRead('alice', $root));

        // Missing marker, invalid username.
        unlink($home.'/.bonus');
        $this->assertSame(0, \pmssUserBonusPercentRead('alice', $root));
        $this->assertSame(0, \pmssUserBonusPercentRead('../etc', $root));
    }

    public function testSliceStateParse(): void
    {
        $this->assertSame(['active' => true, 'weight' => 300], \pmssBonusCpuSliceStateParse("ActiveState=active\nCPUWeight=300\n"));
        $this->assertSame(['active' => true, 'weight' => null], \pmssBonusCpuSliceStateParse("ActiveState=active\nCPUWeight=[not set]"));
        $this->assertSame(['active' => false, 'weight' => 100], \pmssBonusCpuSliceStateParse("CPUWeight=100\nActiveState=inactive"));
        $this->assertSame(null, \pmssBonusCpuSliceStateParse("Failed to get properties: Access denied"));
    }

    public function testCronSourceContracts(): void
    {
        $this->pmssAssertRepoFileContract('scripts/cron/cgroupBonusCpuWeightApply.php', [
            'required' => [
                "require_once __DIR__.'/../lib/cgroup/bonusCpuWeight.php';",
                'pmssCgroupDirectPlannedUsers($USERS_DIR, $total, $errors',
                'pmssCpuApplyBonusWeight($base, pmssUserBonusPercentRead($user))',
                "pmssBuildCommand('systemctl', ['set-property', \$slice, 'CPUWeight='.\$want])",
                'if ($state[\'weight\'] === $want) {',
            ],
            // Priority only: no process signals, no unit restarts, no userConfig re-run, no direct writes.
            'forbidden' => ['kill ', 'posix_kill', "'restart'", "'try-restart'", 'userConfig.php', 'file_put_contents'],
        ]);
        $cron = (string) file_get_contents(__DIR__.'/../../../../etc/seedbox/config/root.cron');
        $this->assertStringContainsString('/scripts/cron/cgroupBonusCpuWeightApply.php >> /var/log/pmss/cgroupBonusCpuWeightApply.log 2>&1', $cron);
    }
}
