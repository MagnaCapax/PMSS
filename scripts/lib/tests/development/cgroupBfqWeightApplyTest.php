<?php
/**
 * Hermetic tests for pmssBfqFormulaWeight() — the per-user bfq.weight curve
 * extracted from /scripts/cron/cgroupBfqWeightApply.php into a lib for testability.
 *
 * Covers the documented curve points (250/500/1000/2000/4000/8000/16000/32000/64000 MiB),
 * the clamp at [1, customerMax], and edge cases (0, negative, custom coefficient).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

declare(strict_types=1);

require_once __DIR__.'/../../cgroup/bfqFormula.php';

final class CgroupBfqWeightApplyTest
{
    /** @var int */
    private $pass = 0;

    /** @var int */
    private $fail = 0;

    /** @var array<int, string> */
    private $failures = [];

    public function run(): int
    {
        // Documented curve points with default coefficient 3.535, customer max 700.
        // round(3.535 * sqrt(MiB)) clamped [1, 700].
        $this->assertEquals(56, pmssBfqFormulaWeight(250),  '250 MiB');
        $this->assertEquals(79, pmssBfqFormulaWeight(500),  '500 MiB');
        $this->assertEquals(112, pmssBfqFormulaWeight(1000), '1000 MiB');
        $this->assertEquals(158, pmssBfqFormulaWeight(2000), '2000 MiB');
        $this->assertEquals(224, pmssBfqFormulaWeight(4000), '4000 MiB');
        $this->assertEquals(316, pmssBfqFormulaWeight(8000), '8000 MiB');
        $this->assertEquals(447, pmssBfqFormulaWeight(16000), '16000 MiB');
        $this->assertEquals(640, pmssBfqFormulaWeight(32768), '32 GiB (operator target ~640)');

        // Clamp at the customer ceiling (700) for very large memory.
        $this->assertEquals(700, pmssBfqFormulaWeight(64000), '64 GiB (clamped to 700)');
        $this->assertEquals(700, pmssBfqFormulaWeight(1000000), '1 TiB (still clamped to 700)');

        // Floor at 1 for zero/negative/missing memory.
        $this->assertEquals(1, pmssBfqFormulaWeight(0),  'zero MiB');
        $this->assertEquals(1, pmssBfqFormulaWeight(-1), 'negative MiB');

        // Custom coefficient and custom ceiling.
        $this->assertEquals(79,  pmssBfqFormulaWeight(1000, 2.5, 700), '1000 MiB k=2.5');
        $this->assertEquals(500, pmssBfqFormulaWeight(32768, 3.535, 500), '32 GiB ceiling=500');

        // Invalid ceiling clamps up to 1 (no zero or negative max).
        $this->assertEquals(1, pmssBfqFormulaWeight(1000, 3.535, 0),  '1000 MiB ceiling=0');
        $this->assertEquals(1, pmssBfqFormulaWeight(1000, 3.535, -1), '1000 MiB ceiling=-1');

        // Result type is always int.
        $val = pmssBfqFormulaWeight(2000);
        $this->assertTrue(is_int($val) && $val > 0, 'returns positive int');

        echo "cgroupBfqWeightApplyTest: pass={$this->pass} fail={$this->fail}\n";
        if ($this->fail > 0) {
            foreach ($this->failures as $line) {
                echo "  FAIL: $line\n";
            }
            return 1;
        }
        return 0;
    }

    private function assertEquals(int $expected, int $actual, string $label): void
    {
        if ($expected === $actual) {
            $this->pass++;
            return;
        }
        $this->fail++;
        $this->failures[] = sprintf('%s expected=%d actual=%d', $label, $expected, $actual);
    }

    private function assertTrue(bool $cond, string $label): void
    {
        if ($cond) {
            $this->pass++;
            return;
        }
        $this->fail++;
        $this->failures[] = sprintf('%s: not true', $label);
    }
}

exit((new CgroupBfqWeightApplyTest())->run());
