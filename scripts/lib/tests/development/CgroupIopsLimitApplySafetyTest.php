<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * Source-level guards for the direct cgroup-v1 IOPS writer.
 *
 * The cron script has root-only sysfs preflights at top level, so these tests
 * verify the safety-critical ordering without executing the live entrypoint.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
final class CgroupIopsLimitApplySafetyTest extends TestCase
{
    public function testCronRejectsUnsafePasswdUidBeforeCgroupPath(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/cron/cgroupIopsLimitApply.php',
            [
                '$pwd = posix_getpwnam($user);',
                '$uid = pmssPasswdEntryPositiveUid($pwd);',
                'syslog(LOG_WARNING, "unsafe passwd uid $user");',
                "\$sliceDir = '/sys/fs/cgroup/blkio/user.slice/user-'.\$uid.'.slice';",
            ],
            'missing IOPS passwd UID guard: ',
            'IOPS UID guard must run before sysfs path assembly: '
        );
    }

    public function testCronValidatesDirectWriteTargetBeforeFilePutContents(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/cron/cgroupIopsLimitApply.php',
            [
                'function pmssIopsWriteThrottle(string $cgPath, string $majMin, int $iops, bool $dryRun): array',
                'if (!pmssIopsMajorMinorValid($majMin) || $iops <= 0 || !pmssIopsThrottlePathAllowed($cgPath))',
                "return ['ok' => false, 'reason' => 'invalid-target', 'cur' => null];",
                'if (@file_put_contents($cgPath, $desired) === false)',
            ],
            'missing IOPS direct-write guard: ',
            'IOPS direct-write guard must run before file_put_contents: '
        );
    }

    public function testCronKeepsThrottlePathPatternPinnedToPerUserBlkioFiles(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/cron/cgroupIopsLimitApply.php',
            [
                'function pmssIopsThrottlePathAllowed(string $cgPath): bool',
                '#^/sys/fs/cgroup/blkio/user\.slice/user-[1-9][0-9]*\.slice/blkio\.throttle\.(read|write)_iops_device$#',
                'function pmssIopsMajorMinorValid(string $majMin): bool',
                "return preg_match('/^[0-9]+:[0-9]+$/', \$majMin) === 1;",
            ],
            'missing IOPS sysfs path guard: '
        );
    }

    public function testCronValidatesIopsSpecPrefixBeforeParsingNumericSuffix(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/cron/cgroupIopsLimitApply.php',
            [
                'function pmssIopsParseSpec($raw): ?int',
                'Do not let an arbitrary "label:number" string trigger a /home throttle.',
                "if (preg_match('#^(?:/home|/dev/[^:\\r\\n\\x00]+):([0-9]+)$#', \$raw, \$m) !== 1) {",
                '$n = (int) $m[1];',
            ],
            'missing IOPS config spec guard: ',
            'IOPS config spec guard must run before suffix parsing: '
        );
    }
}
