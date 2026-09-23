<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../cgroup/directApply.php';
require_once __DIR__.'/../../cgroup/policy.php';

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
    public function testMajorMinorGuardConsumesTheEntireWriteToken(): void
    {
        foreach (['0:0', '8:0', '259:65535', '008:000', '4294967295:4294967295'] as $token) {
            $this->assertTrue(\pmssCgroupPolicyMajorMinorIsValid($token));
        }
        foreach (["8:0\n", "8:0\r\n", "8:0\r", "8:0\0", "\n8:0", '8:0 ',
            "8:0\n8:1", '8:0 100', '', '8:', ':0', '-8:0', '8:0.5'] as $token) {
            $this->assertFalse(\pmssCgroupPolicyMajorMinorIsValid($token), json_encode($token));
        }
    }

    public function testMajorMinorResolverPreservesSysfsLineNormalization(): void
    {
        // Sysfs returns a newline-terminated record; normalization belongs at the read boundary.
        foreach (['8:0', "8:0\n", "8:0\r\n", " 8:0\n", "\t8:0\n"] as $raw) {
            $this->assertSame('8:0', \pmssCgroupPolicyDeviceMajorMinorResolve('/dev/sda',
                static function (string $path) use ($raw): string { return $raw; }));
        }
        foreach (['', 'error', "8:0\n8:1", '8:', '8:0 100'] as $raw) {
            $this->assertSame(null, \pmssCgroupPolicyDeviceMajorMinorResolve('/dev/sda',
                static function (string $path) use ($raw): string { return $raw; }));
        }
    }

    public function testCronSourceSafetyContracts(): void
    {
        $this->pmssAssertRepoFileContract('scripts/cron/cgroupIopsLimitApply.php', [
                'ordered' => [
                    [
                        'needles' => [
                            'foreach ($userConfigs as $user => $json) {',
                            'if (($uid = pmssCgroupDirectUserUidOrError($user, $errors)) === null) {',
                            '$limits = [$readIops, $writeIops];',
                            '$sliceDir = pmssCgroupDirectUserSliceDir($uid);',
                        ],
                        'missingPrefix' => 'missing IOPS passwd UID guard: ',
                        'orderPrefix' => 'IOPS UID guard must run before sysfs path assembly: ',
                    ],
                    [
                        'needles' => [
                            'function pmssIopsWriteThrottle(string $cgPath, string $majMin, int $iops, bool $dryRun): array',
                            "pmssCgroupDirectUserBlkioPathAllowed(\$cgPath, ['blkio.throttle.read_iops_device', 'blkio.throttle.write_iops_device'])",
                            "return ['ok' => false, 'reason' => 'invalid-target', 'cur' => null];",
                            'if (@file_put_contents($cgPath, $desired) === false)',
                        ],
                        'missingPrefix' => 'missing IOPS direct-write guard: ',
                        'orderPrefix' => 'IOPS direct-write guard must run before file_put_contents: ',
                    ],
                    [
                        'needles' => [
                            'function pmssIopsParseSpec($raw): ?int',
                            'Do not let an arbitrary "label:number" string trigger a /home throttle.',
                            "if (preg_match('#^(?:/home|/dev/[^:\\r\\n\\x00]+):([0-9]+)$#', \$raw, \$m) !== 1) {",
                            '$n = (int) $m[1];',
                        ],
                        'missingPrefix' => 'missing IOPS config spec guard: ',
                        'orderPrefix' => 'IOPS config spec guard must run before suffix parsing: ',
                    ],
                ],
            ]);
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
        ] as [$path, $expected]) {
            $this->assertSame($expected, \pmssCgroupDirectUserBlkioPathAllowed($path, $allowed));
        }
    }

    public function testAbsentBlkioHierarchyIsCleanNoopForRootRuntime(): void
    {
        $missingRoot = $this->pmssMakeTempPath('pmss-missing-blkio-');
        $check = \pmssCgroupDirectRuntimeCheck('INFO: /sys/fs/cgroup/blkio absent (cgroup-v2 host); not applicable here', 0, $missingRoot);

        $this->assertSame(0, $check['exitCode']);
        $this->assertSame('INFO: /sys/fs/cgroup/blkio absent (cgroup-v2 host); not applicable here', $check['message']);
    }

    public function testPlannedUsersPreserveCycleAccountingAndResolverFailures(): void
    {
        $usersDir = $this->pmssMakeTempDir('pmss-cgroup-users-', 0700);
        $this->pmssWriteFile($usersDir.'/alice.json', '{"enabled":true}', 0700);
        $this->pmssWriteFile($usersDir.'/bob.json', '{"enabled":true}', 0700);
        $this->pmssWriteFile($usersDir.'/skipme.json', '{"enabled":false}', 0700);
        $this->pmssWriteFile($usersDir.'/badjson.json', '{', 0700);
        $this->pmssWriteFile($usersDir.'/bad name.json', '{"enabled":true}', 0700);

        $total = 0;
        $errors = 0;
        $seenResolvers = [];
        $planned = iterator_to_array(\pmssCgroupDirectPlannedUsers(
            $usersDir,
            $total,
            $errors,
            static function (string $user, array $json): ?array {
                return ($json['enabled'] ?? false) === true ? ['plan' => $user.'-work'] : null;
            },
            static function (string $user, int &$errors) use (&$seenResolvers): ?int {
                $seenResolvers[] = $user;
                if ($user === 'bob') {
                    $errors++;
                    return null;
                }
                return 1000 + count($seenResolvers);
            }
        ), false);

        $this->assertSame(2, $total, 'only planned valid configs count toward total');
        $this->assertSame(3, $errors, 'bad json, invalid username, and failed UID resolution count as errors');
        $this->assertEquals(['alice', 'bob'], $seenResolvers, 'skipped plans must not resolve UIDs');
        $this->assertSame(1, count($planned));
        $this->assertSame('alice', $planned[0][0]);
        $this->assertSame(1001, $planned[0][1]);
        $this->assertEquals(['plan' => 'alice-work'], $planned[0][2]);
    }

}
