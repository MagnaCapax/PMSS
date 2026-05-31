<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../cgroup/policy.php';

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
            [64000, 700],
            [1000000, 700],
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
            [1000, 2.5, 700, 79, 'custom coefficient'],
            [32768, 3.535, 500, 500, 'custom ceiling'],
            [1000, 3.535, 0, 1, 'zero ceiling'],
            [1000, 3.535, -1, 1, 'negative ceiling'],
        ] as [$memoryMiB, $coefficient, $customerMax, $expected, $label]) {
            $this->assertEquals(
                $expected,
                \pmssBfqFormulaWeight($memoryMiB, $coefficient, $customerMax),
                'Unexpected weight for '.$label
            );
        }
    }

    public function testCronValidatesConfigUsernameBeforeUserPaths(): void
    {
        $source = $this->pmssReadRepoFile('scripts/cron/cgroupBfqWeightApply.php');

        $this->assertStringContainsAllStrings(["require_once __DIR__.'/../lib/user/identity.php';", 'if (!pmssValidateUsername($user)) {', 'pmssBfqUserBonusPercentRead($user)'], $source);
        $this->assertOrderedStrings(
            [
                "\$user = basename(\$cfgPath, '.json');",
                'syslog(LOG_WARNING, "invalid username config "',
                '$json = pmssJsonFileReadAssoc($cfgPath);',
                'pmssBfqUserBonusPercentRead($user)',
            ],
            $source,
            'missing BFQ username boundary guard: ',
            'BFQ username guard must run before user paths: '
        );
    }

    public function testCronReadsBonusMarkerAsTinyRegularFile(): void
    {
        $source = $this->pmssReadRepoFile('scripts/cron/cgroupBfqWeightApply.php');

        $this->assertStringContainsAllStrings(['function pmssBfqUserBonusPercentRead(string $user): int', '$stat = @lstat($path);', "((\$stat['mode'] ?? 0) & 0170000) !== 0100000", "(int) (\$stat['size'] ?? 0) > 64", '@file_get_contents($path, false, null, 0, 64)'], $source);
    }
}
