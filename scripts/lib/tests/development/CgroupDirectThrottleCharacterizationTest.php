<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Lock directApply.php's set/clear results and I/O order using memory-only sysfs observations. */
class CgroupDirectThrottleCharacterizationTest extends TestCase
{
    private function observe(array $cases): array
    {
        $script = <<<'PHP'
namespace CgroupDirectThrottleFixture;
function is_file($path) { $GLOBALS['events'][] = 'file'; return $GLOBALS['state']['exists'] ?? true; }
function is_writable($path) { $GLOBALS['events'][] = 'writable'; return $GLOBALS['state']['writable'] ?? true; }
function file_get_contents($path) {
    $GLOBALS['events'][] = 'read';
    if ($GLOBALS['state']['throwRead'] ?? false) throw new \RuntimeException('fixture read');
    return $GLOBALS['state']['raw'] ?? '8:0 100';
}
function file_put_contents($path, $value) {
    $GLOBALS['events'][] = ['write', $value];
    if ($GLOBALS['state']['throwWrite'] ?? false) throw new \Error('fixture write');
    return $GLOBALS['state']['writeResult'] ?? strlen($value);
}
PHP;
        $script .= 'require_once '.var_export($this->pmssRepoPath('scripts/lib/cgroup/policy.php'), true).';';
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/cgroup/directApply.php', 'CgroupDirectThrottleFixture');
        $script .= '$cases = '.var_export($cases, true).';';
        $script .= <<<'PHP'
$observed = [];
foreach ($cases as $case) {
    $GLOBALS['state'] = $case; $GLOBALS['events'] = [];
    $path = $case['path'] ?? '/sys/fs/cgroup/blkio/user.slice/user-1001.slice/blkio.throttle.read_iops_device';
    $device = $case['device'] ?? '8:0'; $dry = $case['dry'] ?? false;
    try {
        $result = ($case['clear'] ?? false) ? pmssIopsClearThrottle($path, $device, $dry)
            : pmssIopsWriteThrottle($path, $device, $case['iops'] ?? 200, $dry);
    } catch (\Throwable $error) {
        $result = [get_class($error), $error->getMessage()];
    }
    $observed[] = [$result, $GLOBALS['events']];
}
echo json_encode($observed);
PHP;
        return $this->pmssRunInlinePhpJson($script);
    }

    public function testInvalidTargetsNeverReachFilesystem(): void
    {
        $cases = [['device' => "8:0\n"], ['device' => "8:0\0"], ['device' => ''], ['iops' => 0], ['iops' => -1]];
        foreach (['/tmp/fixture', '/sys/fs/cgroup/blkio/user.slice/user-0.slice/blkio.throttle.read_iops_device',
            '/sys/fs/cgroup/blkio/user.slice/user-1001.slice/blkio.throttle.read_bps_device'] as $path) {
            $cases[] = ['path' => $path];
            $cases[] = ['path' => $path, 'clear' => true];
        }
        $cases[] = ['device' => "8:0\n", 'clear' => true];
        foreach ($this->observe($cases) as $row) {
            $this->assertSame([['ok' => false, 'reason' => 'invalid-target', 'cur' => null], []], $row);
        }
    }

    public function testMissingAndUnwritableFilesRetainDifferentModePolicies(): void
    {
        $this->assertSame([
            [['ok' => false, 'reason' => 'unwritable', 'cur' => null], ['file']],
            [['ok' => true, 'reason' => 'no-slice', 'cur' => null], ['file']],
            [['ok' => false, 'reason' => 'unwritable', 'cur' => null], ['file', 'writable']],
            [['ok' => false, 'reason' => 'write-failed', 'cur' => '8:0 100'], ['file', 'read', 'writable']],
            [['ok' => true, 'reason' => 'already-clear', 'cur' => '8:1 100'], ['file', 'read']],
        ], $this->observe([['exists' => false], ['exists' => false, 'clear' => true], ['writable' => false],
            ['clear' => true, 'writable' => false], ['clear' => true, 'writable' => false, 'raw' => '8:1 100']]));
    }

    public function testSetMatchesAnyExactLineAndKeepsTheCapturedState(): void
    {
        foreach ([" 8:1 900\n8:0 200\n", "8:0 bad\n 8:0 200 ", "8:0 100\n8:0 200"] as $raw) {
            $this->assertSame([[['ok' => true, 'reason' => 'already-set', 'cur' => trim($raw)],
                ['file', 'writable', 'read']]], $this->observe([['raw' => $raw, 'dry' => true]]));
        }
        // Numerically equivalent text is still a write in set mode.
        $this->assertSame([[['ok' => true, 'reason' => 'dry-run', 'cur' => '8:0 0200'],
            ['file', 'writable', 'read']]], $this->observe([['raw' => '8:0 0200', 'dry' => true]]));
    }

    public function testClearUsesTheFirstParsedRecordAndTreatsZeroAsPresent(): void
    {
        foreach (['', '8:1 100', "8:0 bad\n8:0 100", '8:0 100 extra', "8:0 10\0 0", '8:0 '.str_repeat('9', 30)] as $raw) {
            $this->assertSame([[['ok' => true, 'reason' => 'already-clear', 'cur' => trim($raw)], ['file', 'read']]],
                $this->observe([['clear' => true, 'raw' => $raw]]));
        }
        foreach (['8:0 0', "8:0 100\0"] as $raw) {
            $this->assertSame([[['ok' => true, 'reason' => 'written', 'cur' => trim($raw)],
                ['file', 'read', 'writable', ['write', '8:0 0']]]], $this->observe([['clear' => true, 'raw' => $raw]]));
        }
    }

    public function testDryRunWriteResultsAndThrowablesKeepTheirBoundaries(): void
    {
        foreach ([false, true] as $clear) {
            $reads = $clear ? ['file', 'read'] : ['file', 'writable', 'read'];
            $writes = array_merge($reads, $clear ? ['writable'] : [], [['write', $clear ? '8:0 0' : '8:0 200']]);
            $this->assertSame([[['ok' => true, 'reason' => 'dry-run', 'cur' => '8:0 100'], $reads]],
                $this->observe([['clear' => $clear, 'dry' => true, 'writable' => $clear ? false : true]]));
            foreach ([0, 1, 7] as $bytes) {
                $this->assertSame([[['ok' => true, 'reason' => 'written', 'cur' => '8:0 100'], $writes]],
                    $this->observe([['clear' => $clear, 'writeResult' => $bytes]]));
            }
            $this->assertSame([[['ok' => false, 'reason' => 'write-failed', 'cur' => '8:0 100'], $writes]],
                $this->observe([['clear' => $clear, 'writeResult' => false]]));
            $this->assertSame([[['RuntimeException', 'fixture read'], $reads]],
                $this->observe([['clear' => $clear, 'throwRead' => true]]));
            $this->assertSame([[['Error', 'fixture write'], $writes]],
                $this->observe([['clear' => $clear, 'throwWrite' => true]]));
        }
    }

    public function testUidFailuresRetainTheirWarningAndErrorCount(): void
    {
        $script = <<<'PHP'
namespace CgroupDirectUidFixture;
function posix_getpwnam($user) { return $GLOBALS['pwd']; }
function syslog($level, $message) { $GLOBALS['messages'][] = $message; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/cgroup/directApply.php', 'CgroupDirectUidFixture');
        $script .= <<<'PHP'
$rows = [];
foreach ([false, ['uid' => 0], ['uid' => 'bad'], ['uid' => '1001'], ['uid' => 1002]] as $pwd) {
    $GLOBALS['pwd'] = $pwd; $GLOBALS['messages'] = []; $errors = 5;
    $uid = pmssCgroupDirectUserUidOrError('fixtur01', $errors);
    $rows[] = [$uid, $errors, $GLOBALS['messages']];
}
echo json_encode($rows);
PHP;
        $this->assertSame([[null, 6, ['no passwd entry fixtur01']], [null, 6, ['unsafe passwd uid fixtur01']],
            [null, 6, ['unsafe passwd uid fixtur01']], [1001, 5, []], [1002, 5, []]], $this->pmssRunInlinePhpJson($script));
    }
}
