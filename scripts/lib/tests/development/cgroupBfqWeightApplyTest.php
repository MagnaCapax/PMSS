<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../cgroup/policy.php';
require_once __DIR__.'/../../user/identity.php';

/**
 * Hermetic tests for the per-user bfq.weight fallback and bonus helpers
 * shared by /scripts/cron/cgroupBfqWeightApply.php.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
class CgroupBfqWeightApplyTest extends TestCase
{
    // Curve points with the default bonus-headroom curve.
    // round(0.690533966002 * sqrt(MiB)) clamped [1, 250].

    public function testDefaultCurvePointsAndClamps(): void
    {
        foreach ([
            [250, 11],
            [500, 15],
            [1000, 22],
            [2000, 31],
            [4000, 44],
            [8000, 62],
            [16000, 87],
            [32768, 125],
            [64000, 175],
            [\PMSS_BFQ_FALLBACK_REFERENCE_MEMORY_MIB, \PMSS_BFQ_FALLBACK_BASE_MAX],
            [262144, \PMSS_BFQ_FALLBACK_BASE_MAX],
            [1000000, \PMSS_BFQ_FALLBACK_BASE_MAX],
            [0, 1],
            [-1, 1],
        ] as [$memoryMiB, $expected]) {
            $actual = \pmssBfqFormulaWeight($memoryMiB);
            $this->assertEquals($expected, $actual, 'Unexpected weight for '.$memoryMiB.' MiB');
            $this->assertTrue(is_int($actual) && $actual > 0);
        }
    }

    public function testDefaultCurveReservesDocumentedBonusHeadroom(): void
    {
        foreach ([250, 32768, 64000, \PMSS_BFQ_FALLBACK_REFERENCE_MEMORY_MIB, 262144] as $memoryMiB) {
            $base = \pmssBfqFormulaWeight($memoryMiB);
            $boosted = \pmssBfqApplyBonusWeight($base, \PMSS_BFQ_FALLBACK_MAX_BONUS_PERCENT);
            $this->assertTrue($boosted <= \PMSS_BFQ_KERNEL_MAX, 'Bonus overflow for '.$memoryMiB.' MiB');
        }

        $this->assertSame(500, \pmssBfqApplyBonusWeight(\pmssBfqFormulaWeight(32768), 300));
        $this->assertSame(700, \pmssBfqApplyBonusWeight(\pmssBfqFormulaWeight(64000), 300));
        $referenceBase = \pmssBfqFormulaWeight(\PMSS_BFQ_FALLBACK_REFERENCE_MEMORY_MIB);
        $this->assertSame(1000, \pmssBfqApplyBonusWeight($referenceBase, 300));
    }

    public function testBonusApplicationClampsAfterMultiplier(): void
    {
        foreach ([
            [125, 0, 125],
            [125, 300, 500],
            [250, 300, 1000],
            [250, 400, 1000],
            [-10, 0, 1],
            [10, -50, 10],
            [1000, 0, 1000],
            [1000, 1, 1000],
        ] as [$baseWeight, $bonusPct, $expected]) {
            $this->assertSame(
                $expected,
                \pmssBfqApplyBonusWeight($baseWeight, $bonusPct),
                'Unexpected bonus weight for base '.$baseWeight.' and bonus '.$bonusPct
            );
        }
    }

    public function testKernelWeightParserAcceptsOnlyKernelRange(): void
    {
        foreach ([
            ["1\n", 1],
            ['250', 250],
            ["1000\n", 1000],
            ['001', 1],
            ['', null],
            [" \n", null],
            ['0', null],
            ['1001', null],
            ['42 extra', null],
            [false, null],
        ] as [$raw, $expected]) {
            $this->assertSame($expected, \pmssBfqKernelWeightParse($raw));
        }
    }

    public function testSharedPasswdUidParserAcceptsOnlyPositiveIntegerUid(): void
    {
        foreach ([
            [['uid' => 1], 1],
            [['uid' => 1000], 1000],
            [['uid' => '1000'], 1000],
            [['uid' => 0], null],
            [['uid' => '0'], null],
            [['uid' => -1], null],
            [['uid' => '12x'], null],
            [['uid' => ''], null],
            [['uid' => 1.5], null],
            [['gid' => 1000], null],
            [false, null],
            [['uid' => '9999999999999999999999999'], null],
        ] as [$passwdEntry, $expected]) {
            $this->assertSame($expected, \pmssPasswdEntryPositiveUid($passwdEntry));
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

    public function testCronRequiresPosixBeforeRootCheck(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/cron/cgroupBfqWeightApply.php',
            [
                "if (!function_exists('posix_geteuid') || !function_exists('posix_getpwnam')) {",
                'FATAL: POSIX extension required to resolve managed user UIDs',
                'if (posix_geteuid() !== 0) {',
            ],
            'missing BFQ POSIX extension guard: ',
            'BFQ POSIX guard must run before root preflight: '
        );
    }

    public function testCronValidatesConfigUsernameBeforeUserPaths(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/cron/cgroupBfqWeightApply.php',
            [
                "require_once __DIR__.'/../lib/user/identity.php';",
                'if (!pmssValidateUsername($user)) {',
                'pmssBfqUserBonusPercentRead($user)',
                'pmssBfqApplyBonusWeight($wRaw, $bonusPct',
            ]
        );
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/cron/cgroupBfqWeightApply.php',
            [
                "\$user = basename(\$cfgPath, '.json');",
                'syslog(LOG_WARNING, "invalid username config "',
                '$json = pmssJsonFileReadAssoc($cfgPath);',
                'pmssBfqUserBonusPercentRead($user)',
            ],
            'missing BFQ username boundary guard: ',
            'BFQ username guard must run before user paths: '
        );
    }

    public function testCronRejectsUnsafePasswdUidBeforeCgroupPath(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/cron/cgroupBfqWeightApply.php',
            [
                '$pwd = posix_getpwnam($user);',
                '$uid = pmssPasswdEntryPositiveUid($pwd);',
                'syslog(LOG_WARNING, "unsafe passwd uid $user");',
                "\$cgPath = '/sys/fs/cgroup/blkio/user.slice/user-'.\$uid.'.slice/blkio.bfq.weight';",
            ],
            'missing BFQ passwd UID guard: ',
            'BFQ passwd UID guard must run before sysfs path assembly: '
        );
    }

    public function testCronValidatesDirectWriteTargetBeforeFilePutContents(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/cron/cgroupBfqWeightApply.php',
            [
                'function pmssBfqWeightPathAllowed(string $cgPath): bool',
                '#^/sys/fs/cgroup/blkio/user\.slice/user-[1-9][0-9]*\.slice/blkio\.bfq\.weight$#',
                "\$cgPath = '/sys/fs/cgroup/blkio/user.slice/user-'.\$uid.'.slice/blkio.bfq.weight';",
                'if (!pmssBfqWeightPathAllowed($cgPath)) {',
                'syslog(LOG_WARNING, "unsafe bfq target $user uid=$uid");',
                'if (@file_put_contents($cgPath, (string) $w) === false)',
            ],
            'missing BFQ direct-write target guard: ',
            'BFQ direct-write target guard must run before file_put_contents: '
        );
    }

    public function testCronReadsBonusMarkerAsTinyRegularFile(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/cron/cgroupBfqWeightApply.php',
            ['function pmssBfqUserBonusPercentRead(string $user): int', '$stat = @lstat($path);', "((\$stat['mode'] ?? 0) & 0170000) !== 0100000", "(int) (\$stat['size'] ?? 0) > 64", '@file_get_contents($path, false, null, 0, 64)']
        );
    }

    public function testCronRejectsUnreadableCurrentKernelWeight(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/cron/cgroupBfqWeightApply.php',
            [
                '$cur = pmssBfqKernelWeightParse(@file_get_contents($cgPath));',
                "if (\$cur === null) {\n        \$errors++;\n        syslog(LOG_WARNING, \"unreadable bfq weight \$user uid=\$uid\");\n        continue;\n    }",
                'if ($cur === $w) {',
            ],
            'missing BFQ current-weight parse guard: ',
            'BFQ current-weight guard must run before compare/write: '
        );
    }
}
