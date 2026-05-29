<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../cgroup/bfqFormula.php';
require_once __DIR__.'/../../cgroup/bfqWeightTarget.php';

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

    public function testCgroupTargetPathsCoverV1AndV2Fallback(): void
    {
        $root = $this->pmssMakeTempDir('pmss-cgroup-root-');

        $this->assertEquals(
            $root.'/blkio/user.slice/user-1001.slice/blkio.bfq.weight',
            \pmssCgroupBfqWeightTargetPath('v1', 1001, $root)
        );
        $this->assertEquals(
            $root.'/user.slice/user-1001.slice/io.weight',
            \pmssCgroupBfqWeightTargetPath('v2', 1001, $root)
        );
        $this->assertEquals('', \pmssCgroupBfqWeightTargetPath('unknown', 1001, $root));
    }

    public function testCgroupV2PrefersBfqSpecificWeightWhenPresent(): void
    {
        $root = $this->pmssMakeTempDir('pmss-cgroup-root-');
        $slice = $root.'/user.slice/user-1001.slice';
        $this->assertTrue(@mkdir($slice, 0700, true), 'Expected v2 slice fixture');
        $this->pmssWriteFile($slice.'/io.bfq.weight', "default 100\n");

        $this->assertEquals(
            $slice.'/io.bfq.weight',
            \pmssCgroupBfqWeightTargetPath('v2', 1001, $root)
        );
    }

    public function testCgroupControllerReadinessUsesModeSpecificSignals(): void
    {
        $root = $this->pmssMakeTempDir('pmss-cgroup-root-');
        $this->assertFalse(\pmssCgroupBfqWeightControllerReady('v1', $root));
        $this->assertTrue(@mkdir($root.'/blkio', 0700, true), 'Expected v1 blkio fixture');
        $this->assertTrue(\pmssCgroupBfqWeightControllerReady('v1', $root));

        $this->assertFalse(\pmssCgroupBfqWeightControllerReady('v2', $root));
        $this->pmssWriteFile($root.'/cgroup.controllers', "cpu memory io pids\n");
        $this->assertTrue(\pmssCgroupBfqWeightControllerReady('v2', $root));
        $this->pmssWriteFile($root.'/cgroup.controllers', "cpu memory pids\n");
        $this->assertFalse(\pmssCgroupBfqWeightControllerReady('v2', $root));
    }

    public function testCgroupWeightReadbackParsesBareAndDefaultFormats(): void
    {
        foreach ([
            ["640\n", 640],
            ["  704\n", 704],
            ["default 800\n8:0 100\n", 800],
            ["\n", 0],
            ["default nope\n", 0],
        ] as [$contents, $expected]) {
            $this->assertEquals($expected, \pmssCgroupBfqWeightCurrentValue($contents));
        }
    }

    public function testCgroupWritePayloadMatchesKernelFileFormat(): void
    {
        $this->assertEquals('640', \pmssCgroupBfqWeightWritePayload('v1', 640));
        $this->assertEquals('default 640', \pmssCgroupBfqWeightWritePayload('v2', 640));
    }

    public function testBfqSchedulerDetectionUsesFixtureSchedulers(): void
    {
        $root = $this->pmssMakeTempDir('pmss-block-root-');
        $scheduler = $root.'/sda/queue';
        $this->assertTrue(@mkdir($scheduler, 0700, true), 'Expected scheduler fixture');
        $this->pmssWriteFile($scheduler.'/scheduler', "mq-deadline kyber [none]\n");
        $this->assertFalse(\pmssCgroupBfqWeightBfqSchedulerActive($root));

        $this->pmssWriteFile($scheduler.'/scheduler', "mq-deadline [bfq] none\n");
        $this->assertTrue(\pmssCgroupBfqWeightBfqSchedulerActive($root));
    }
}
