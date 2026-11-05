<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Execute the actual cron branches with every host boundary replaced by in-memory fixtures. */
class CgroupApplyAuditCronTest extends TestCase
{
    private function cycle(string $cron, string $mode, array $args = []): array
    {
        $script = <<<'PHP'
namespace CgroupAuditCronFixture;
function pmssCgroupDirectRequireRuntime($message) {}
function pmssCgroupPolicyLoad() { return []; }
function pmssCgroupPolicyMountBfqActive($mount) { return true; }
function pmssCgroupPolicyMountSourceResolve($mount) { return '/dev/fixture'; }
function pmssCgroupPolicyDeviceMajorMinorResolve($device) { return '8:0'; }
function pmssIoCeilingPublishedReadIops($path) { return null; }
function pmssCgroupPolicyHomeDeviceIsMdBacked($device) { return true; }
function pmssCgroupDirectUserConfigs($dir, &$errors) {
    return [['fixture', $GLOBALS['mode'] === 'write-only' ? ['IOWriteIOPS' => '/home:300'] : ['IOReadIOPS' => '/home:200']]];
}
function pmssCgroupDirectPlannedUsers($dir, &$total, &$errors, $planner) { $total = 1; return [['fixture', 1000, 125]]; }
function pmssCgroupDirectUserUidOrError($user, &$errors) { return 1000; }
function pmssCgroupDirectWritableFileTarget($path) { return true; }
function pmssCgroupDirectUserBlkioPathAllowed($path, $names) { return true; }
function is_dir($path) { return true; }
function is_file($path) { return true; }
function is_writable($path) { return true; }
function file_exists($path) { return true; }
function file_get_contents($path) {
    if (strpos($path, 'bfq.weight') !== false) return $GLOBALS['mode'] === 'unchanged' ? '125' : '100';
    return $GLOBALS['mode'] === 'unchanged' ? "8:0 200\n" : "8:1 900\n8:0 100\n";
}
function file_put_contents($path, $value) {
    $GLOBALS['writes'][] = $value;
    return $GLOBALS['mode'] === 'write-failed' ? false : strlen($value);
}
function pmssCgroupDirectIntegerFileWrite($path, $value, $min, $max) {
    $GLOBALS['writes'][] = $value;
    return $GLOBALS['mode'] !== 'write-failed';
}
function pmssCgroupDirectAudit($user, $uid, $control, $old, $new, $source) {
    $GLOBALS['audits'][] = [$user, $uid, $control, $old, $new];
    return $GLOBALS['mode'] !== 'audit-failed';
}
function pmssCgroupIopsCensusPrint(...$args) { echo "fixture census\n"; }
function pmssCgroupDirectFinishCycle($dryRun, $total, $written, $skipped, $errors) {
    $GLOBALS['summary'] = [$total, $written, $skipped, $errors];
    return $errors > 0 ? 1 : 0;
}
function openlog(...$args) {}
function syslog(...$args) {}
function closelog() {}
$GLOBALS['mode'] = getenv('PMSS_TEST_AUDIT_MODE');
$GLOBALS['writes'] = $GLOBALS['audits'] = []; $GLOBALS['summary'] = null;
$argv = json_decode(getenv('PMSS_TEST_AUDIT_ARGS'), true);
ob_start();
register_shutdown_function(static function () {
    ob_end_clean();
    echo json_encode([$GLOBALS['audits'], $GLOBALS['writes'], $GLOBALS['summary']]);
});
PHP;
        // The real writer lives in the library; run it in this namespace so its I/O stays in the fixture.
        $library = file_get_contents($this->pmssRepoPath('scripts/lib/cgroup/directApply.php'));
        $script .= 'eval('.var_export('namespace CgroupAuditCronFixture;'.substr($library, (int) strpos($library, 'function pmssIopsWriteThrottle')), true).');';
        $path = $this->pmssRepoPath('scripts/cron/'.$cron.'.php');
        $source = file_get_contents($path);
        // Preserve the cron body and exit paths; eval needs the file prologue removed.
        $source = substr($source, strpos($source, '<?php') + 5);
        $source = str_replace(['declare(strict_types=1);', '__DIR__'], ['', var_export(dirname($path), true)], $source);
        $script .= 'eval('.var_export('namespace CgroupAuditCronFixture;'.$source, true).');';
        $result = $this->pmssExecInlinePhp($script, ['PMSS_TEST_AUDIT_MODE' => $mode,
            'PMSS_TEST_AUDIT_ARGS' => json_encode($args), 'PMSS_DRY_RUN' => '0'], '2>/dev/null');
        return array_merge($this->pmssDecodeJsonArray($result['output']), [$result['rc']]);
    }

    public function testOnlySuccessfulChangedWritesReachAudit(): void
    {
        foreach (['cgroupIopsLimitApply' => ['read_iops', 200], 'cgroupBfqWeightApply' => ['bfq_weight', 125]] as $cron => [$control, $new]) {
            foreach (['changed', 'audit-failed'] as $mode) {
                $result = $this->cycle($cron, $mode);
                $this->assertSame([['fixture', 1000, $control, 100, $new]], $result[0]);
                $this->assertSame([$cron === 'cgroupIopsLimitApply' ? '8:0 '.$new : $new], $result[1]);
                $this->assertSame([1, 1, 0, 0], $result[2]);
                $this->assertSame(0, $result[3]);
            }
            $unchanged = $this->cycle($cron, 'unchanged');
            $this->assertSame([[], [], [1, 0, 0, 0], 0], $unchanged);
            $failed = $this->cycle($cron, 'write-failed');
            $this->assertSame([], $failed[0]);
            $this->assertSame([1, 0, 0, 1], $failed[2]);
            $this->assertSame(1, $failed[3]);
            $dryRun = $this->cycle($cron, 'changed', ['--dry-run']);
            $this->assertSame([], $dryRun[0]);
            $this->assertSame([], $dryRun[1]);
        }
        foreach ([['--census'], ['--census', '--dry-run']] as $args) {
            $result = $this->cycle('cgroupIopsLimitApply', 'changed', $args);
            $this->assertSame([], $result[0]);
            $this->assertSame([], $result[1]);
        }
        $this->assertSame([['fixture', 1000, 'write_iops', 100, 300]], $this->cycle('cgroupIopsLimitApply', 'write-only')[0]);
    }
}
