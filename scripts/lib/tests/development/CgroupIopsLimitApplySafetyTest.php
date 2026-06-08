<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../cgroup/directApply.php';

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
                '$uid = pmssCgroupDirectUserUidOrError($user, $errors);',
                'if ($uid === null) {',
                '$sliceDir = pmssCgroupDirectUserSliceDir($uid);',
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
                "pmssCgroupDirectUserBlkioPathAllowed(\$cgPath, ['blkio.throttle.read_iops_device', 'blkio.throttle.write_iops_device'])",
                "return ['ok' => false, 'reason' => 'invalid-target', 'cur' => null];",
                'if (@file_put_contents($cgPath, $desired) === false)',
            ],
            'missing IOPS direct-write guard: ',
            'IOPS direct-write guard must run before file_put_contents: '
        );
    }

    public function testSharedDirectBlkioPathGuardLocksPerUserTargetShapes(): void
    {
        $allowed = ['blkio.throttle.read_iops_device', 'blkio.throttle.write_iops_device'];
        $this->assertSame('/sys/fs/cgroup/blkio/user.slice/user-1000.slice', \pmssCgroupDirectUserSliceDir(1000));
        $this->assertSame('/sys/fs/cgroup/blkio/user.slice/user-1000.slice/blkio.bfq.weight', \pmssCgroupDirectUserBlkioFilePath(1000, 'blkio.bfq.weight'));
        $this->assertTrue(\pmssCgroupDirectUserBlkioPathAllowed('/sys/fs/cgroup/blkio/user.slice/user-1000.slice/blkio.bfq.weight', ['blkio.bfq.weight']));
        foreach ([
            ['/sys/fs/cgroup/blkio/user.slice/user-1000.slice/blkio.throttle.read_iops_device', true],
            ['/sys/fs/cgroup/blkio/user.slice/user-1000.slice/blkio.throttle.write_iops_device', true],
            ['/sys/fs/cgroup/blkio/user.slice/user-1000.slice/blkio.bfq.weight', false],
            ['/sys/fs/cgroup/blkio/user.slice/user-0.slice/blkio.throttle.read_iops_device', false],
            ['/sys/fs/cgroup/blkio/user.slice/user-1000.slice/../blkio.throttle.read_iops_device', false],
        ] as $case) {
            $this->assertSame($case[1], \pmssCgroupDirectUserBlkioPathAllowed($case[0], $allowed));
        }
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
