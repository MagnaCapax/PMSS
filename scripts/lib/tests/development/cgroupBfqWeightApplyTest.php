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
    // Curve points with default coefficient 3.535, kernel max 1000, bonus 0.
    // round(3.535 * sqrt(MiB)) clamped [1, 1000].

    public function testDefaultCurvePointsAndClamps(): void
    {
        foreach ([
            [250, 56],
            [500, 79],
            [1000, 112],
            [2000, 158],
            [4000, 224],
            [8000, 316],
            [16000, 447],
            [32768, 640],
            [64000, 894],
            [80000, 1000],
            [1000000, 1000],
            [0, 1],
            [-1, 1],
        ] as [$memoryMiB, $expected]) {
            $actual = \pmssBfqFormulaWeight($memoryMiB);
            $this->assertEquals($expected, $actual, 'Unexpected weight for '.$memoryMiB.' MiB');
            $this->assertTrue(is_int($actual) && $actual > 0);
        }
    }

    public function testCustomParametersAndInvalidCeilings(): void
    {
        foreach ([
            [1000, 2.5, 1000, 79, 'custom coefficient'],
            [32768, 3.535, 500, 500, 'custom ceiling'],
            [1000, 3.535, 0, 1, 'zero ceiling'],
            [1000, 3.535, -1, 1, 'negative ceiling'],
        ] as [$memoryMiB, $coefficient, $kernMax, $expected, $label]) {
            $this->assertEquals(
                $expected,
                \pmssBfqFormulaWeight($memoryMiB, $coefficient, $kernMax),
                'Unexpected weight for '.$label
            );
        }
    }

    public function testBonusPercentMultipliesBase(): void
    {
        // Base at 32768 MiB = 640. Bonus multiplies the RAM-derived base.
        foreach ([
            [32768, 0.0, 640, 'zero bonus matches base'],
            [32768, 10.0, 704, '10% bonus crosses old 700 hold'],
            [32768, 25.0, 800, '25% bonus -> 800'],
            [32768, 50.0, 960, '50% bonus -> 960'],
            [32768, 100.0, 1000, '100% bonus clamps to kernMax 1000'],
            [32768, 300.0, 1000, '300% bonus clamps to kernMax 1000'],
            [8000, 100.0, 632, '100% bonus on 8 GiB plan'],
            [1000, 200.0, 335, '200% bonus on 1 GiB plan'],
            [32768, -50.0, 640, 'negative bonus treated as zero'],
            [0, 300.0, 1, 'zero RAM with bonus still returns 1'],
        ] as [$memoryMiB, $bonusPct, $expected, $label]) {
            $this->assertEquals(
                $expected,
                \pmssBfqFormulaWeight($memoryMiB, 3.535, 1000, $bonusPct),
                'Unexpected weight: '.$label
            );
        }
    }
}
