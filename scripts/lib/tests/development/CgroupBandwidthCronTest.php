<?php
/** Verify directBandwidth.php integration in cgroupIopsLimitApply.php with in-memory host boundaries. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class CgroupBandwidthCronTest extends TestCase
{
    private function cycle(string $mode, array $args = []): array
    {
        $script = <<<'PHP'
namespace CgroupBandwidthCronFixture;
function pmssCgroupDirectRequireRuntime($message) {}
function pmssCgroupPolicyLoad() { return ['cgroup' => ['bandwidthEnforce' => $GLOBALS['mode'] !== 'off']]; }
function pmssCgroupPolicyMountSourceResolve($mount) { return '/dev/fixture'; }
function pmssCgroupPolicyDeviceMajorMinorResolve($device) { return $device === '/dev/fixture' ? '9:0' : ($GLOBALS['mode'] === 'baddevice' ? null : '8:0'); }
function pmssIoCeilingPublishedReadIops($path) { return null; }
function pmssCgroupPolicyHomeDeviceIsMdBacked($device) { return true; }
function pmssCgroupDirectUserConfigs($dir, &$errors) { return [['fixtur01', ['IOReadBW' => '/home:5M', 'IOWriteBW' => '/dev/sda:10M']]]; }
function pmssDynamicIopsKernelReady() { return false; }
function pmssDynamicIopsSettings($policy) { return ['mode' => 'off']; }
function pmssCgroupPolicyDerivedReadIopsCap(...$args) { return null; }
function pmssIopsBudgetHeldReadCap(...$args) { return null; }
function pmssIopsBudgetCapRecordRead(...$args) { return null; }
function pmssIopsClearThrottle(...$args) { return ['ok' => true, 'reason' => 'already-clear']; }
function posix_getpwnam($user) { return false; }
function pmssCgroupDirectUserUidOrError($user, &$errors) { return 1001; }
function is_dir($path) { return $GLOBALS['mode'] !== 'missing'; }
function pmssCgroupDirectWritableFileTarget($path) { return true; }
function file_get_contents($path) {
    if ($GLOBALS['mode'] !== 'unchanged') return '';
    return strpos($path, 'read_bps') !== false ? '9:0 5000000' : '8:0 10000000';
}
function file_put_contents($path, $value) {
    $GLOBALS['writes'][] = [basename($path), $value];
    return $GLOBALS['mode'] === 'failed' ? false : strlen($value);
}
function pmssCgroupDirectAudit($user, $uid, $control, $old, $new, $source) { $GLOBALS['audits'][] = [$control, $old, $new]; return true; }
function pmssCgroupIopsCensusPrint(...$args) {}
function pmssCgroupDirectFinishCycle($dryRun, $total, $written, $skipped, $errors) { $GLOBALS['summary'] = [$total, $written, $skipped, $errors]; return $errors > 0 ? 1 : 0; }
function openlog(...$args) {}
function syslog(...$args) {}
function closelog() {}
PHP;
        $script .= $this->pmssInlinePhpFileWriteCompleteShim();
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/cgroup/directBandwidth.php', 'CgroupBandwidthCronFixture');
        $script .= '$GLOBALS["mode"] = '.var_export($mode, true).'; $argv = '.var_export($args, true).';';
        $script .= <<<'PHP'
$GLOBALS['writes'] = $GLOBALS['audits'] = []; $GLOBALS['summary'] = null;
ob_start();
register_shutdown_function(static function () {
    ob_end_clean();
    echo json_encode([$GLOBALS['writes'], $GLOBALS['audits'], $GLOBALS['summary']]);
});
PHP;
        $source = $this->pmssReadRepoFile('scripts/cron/cgroupIopsLimitApply.php');
        $source = substr($source, strpos($source, '<?php') + 5);
        $source = str_replace(['declare(strict_types=1);', '__DIR__'], ['', var_export($this->pmssRepoPath('scripts/cron'), true)], $source);
        $script .= 'eval('.var_export('namespace CgroupBandwidthCronFixture;'.$source, true).');';
        $result = $this->pmssExecInlinePhp($script, ['PMSS_DRY_RUN' => $mode === 'envdry' ? '1' : '0'], '2>/dev/null');
        return array_merge($this->pmssDecodeJsonArray($result['output']), [$result['rc']]);
    }

    public function testBandwidthOnlyAccountsReachBothWritersAndAudit(): void
    {
        $this->assertSame([[['blkio.throttle.read_bps_device', '9:0 5000000'], ['blkio.throttle.write_bps_device', '8:0 10000000']],
            [['read_bps', null, 5000000], ['write_bps', null, 10000000]], [1, 2, 0, 0], 0], $this->cycle('apply'));
    }

    public function testDisabledUnchangedMissingAndDryCyclesDoNotWrite(): void
    {
        foreach (['off' => [0, 0, 0, 0], 'unchanged' => [1, 0, 0, 0], 'missing' => [1, 0, 1, 0], 'envdry' => [1, 0, 0, 0]] as $mode => $summary) {
            $this->assertSame([[], [], $summary, 0], $this->cycle($mode));
        }
        $this->assertSame([[], [], [1, 0, 0, 0], 0], $this->cycle('apply', ['--dry-run']));
        foreach ([['--census'], ['--census', '--dry-run']] as $args) {
            $this->assertSame([[], [], null, 0], $this->cycle('apply', $args));
        }
    }

    public function testFailedWritesAndUnknownDevicesCountErrorsWithoutAudit(): void
    {
        $failed = $this->cycle('failed');
        $this->assertSame([], $failed[1]);
        $this->assertSame([1, 0, 0, 2], $failed[2]);
        $this->assertSame(1, $failed[3]);
        $this->assertSame([[['blkio.throttle.read_bps_device', '9:0 5000000']], [['read_bps', null, 5000000]],
            [1, 1, 0, 1], 1], $this->cycle('baddevice'));
    }
}
