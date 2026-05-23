<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../cgroup/bfqFormula.php';

/**
 * Hermetic tests for pmssBfqFormulaWeight() — the per-user bfq.weight curve
 * extracted from /scripts/cron/cgroupBfqWeightApply.php into a lib for testability.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
class CgroupBfqWeightApplyTest extends TestCase
{
    // Curve points with default coefficient 3.535, customer max 700.
    // round(3.535 * sqrt(MiB)) clamped [1, 700].

    public function testFormula250MiB(): void
    {
        $this->assertEquals(56, \pmssBfqFormulaWeight(250));
    }

    public function testFormula500MiB(): void
    {
        $this->assertEquals(79, \pmssBfqFormulaWeight(500));
    }

    public function testFormula1000MiB(): void
    {
        $this->assertEquals(112, \pmssBfqFormulaWeight(1000));
    }

    public function testFormula2000MiB(): void
    {
        $this->assertEquals(158, \pmssBfqFormulaWeight(2000));
    }

    public function testFormula4000MiB(): void
    {
        $this->assertEquals(224, \pmssBfqFormulaWeight(4000));
    }

    public function testFormula8000MiB(): void
    {
        $this->assertEquals(316, \pmssBfqFormulaWeight(8000));
    }

    public function testFormula16000MiB(): void
    {
        $this->assertEquals(447, \pmssBfqFormulaWeight(16000));
    }

    public function testFormula32GiBHitsOperatorTarget640(): void
    {
        $this->assertEquals(640, \pmssBfqFormulaWeight(32768));
    }

    public function testClamp64GiBToCustomerCeiling(): void
    {
        $this->assertEquals(700, \pmssBfqFormulaWeight(64000));
    }

    public function testClamp1TiBStillCappedAt700(): void
    {
        $this->assertEquals(700, \pmssBfqFormulaWeight(1000000));
    }

    public function testFloorZeroMiB(): void
    {
        $this->assertEquals(1, \pmssBfqFormulaWeight(0));
    }

    public function testFloorNegativeMiB(): void
    {
        $this->assertEquals(1, \pmssBfqFormulaWeight(-1));
    }

    public function testCustomCoefficient(): void
    {
        // 2.5 * sqrt(1000) = 79.057 → 79
        $this->assertEquals(79, \pmssBfqFormulaWeight(1000, 2.5, 700));
    }

    public function testCustomCeilingClampsBelowDefault(): void
    {
        // 32 GiB would yield 640 with default ceiling; here ceiling=500 wins.
        $this->assertEquals(500, \pmssBfqFormulaWeight(32768, 3.535, 500));
    }

    public function testInvalidZeroCeilingClampsToOne(): void
    {
        $this->assertEquals(1, \pmssBfqFormulaWeight(1000, 3.535, 0));
    }

    public function testInvalidNegativeCeilingClampsToOne(): void
    {
        $this->assertEquals(1, \pmssBfqFormulaWeight(1000, 3.535, -1));
    }

    public function testResultIsPositiveInt(): void
    {
        $val = \pmssBfqFormulaWeight(2000);
        $this->assertTrue(is_int($val) && $val > 0);
    }
}
