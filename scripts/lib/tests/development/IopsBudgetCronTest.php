<?php
/** Verify monthly IOPS holds survive cgroupIopsLimitApply.php cycles using memory-only host boundaries. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class IopsBudgetCronTest extends TestCase
{
    private const READ_PATH = '/sys/fs/cgroup/blkio/user.slice/user-1001.slice/blkio.throttle.read_iops_device';
    private const MARKER = '/fixture/runtime/iopsLimitEnforced/fixtur01';
    private const RECORD = '/fixture/runtime/iopsBudgetCap/fixtur01';

    /** Run the real cron, held-cap policy and guarded throttle writer; carry kernel and marker state between runs. */
    private function cycle(array $options = [], array $args = []): array
    {
        $options += ['mode' => 'enforce', 'ready' => true, 'normal' => 2000, 'budget' => 10000000,
            'kernel' => [self::READ_PATH => '8:0 2000'], 'files' => [self::MARKER => '1700000000']];
        $script = <<<'PHP'
namespace IopsBudgetCronFixture;
function openlog(...$args) {}
function syslog(...$args) {}
function closelog() {}
function posix_geteuid() { return 0; }
function posix_getpwnam($user) { return ['uid' => 1001]; }
function glob($pattern) { return ['/etc/seedbox/config/users/fixtur01.json']; }
// Sysfs exists only in this map; no writer reaches the testing host's controllers.
function is_dir($path) { return true; }
function is_file($path) { return isset($GLOBALS['state']['kernel'][$path]); }
function is_writable($path) { return true; }
function file_get_contents($path) { return $GLOBALS['state']['kernel'][$path] ?? ''; }
function file_put_contents($path, $value) {
    $GLOBALS['state']['writes'][] = [$path, $value]; $GLOBALS['state']['kernel'][$path] = $value; return strlen($value);
}
function pmssJsonFileReadAssoc(...$args) { return ['IOReadIOPS' => '/home:'.$GLOBALS['options']['normal']]; }
function pmssCgroupPolicyLoad() { return ['cgroup' => ['dynamicIops' => ['mode' => $GLOBALS['options']['mode']]]]; }
function pmssCgroupPolicyMountSourceResolve($mount) { return '/dev/fixture'; }
function pmssCgroupPolicyDeviceMajorMinorResolve($device) { return '8:0'; }
function pmssCgroupPolicyHomeDeviceIsMdBacked($device) { return true; }
function pmssIoCeilingPublishedReadIops($path) { return null; }
function pmssCgroupPolicyDerivedReadIopsCap(...$args) { return null; }
function pmssDynamicIopsKernelReady() { return $GLOBALS['options']['ready']; }
function pmssDynamicIopsSettings($policy) { return ['mode' => $GLOBALS['options']['mode']]; }
// An unavailable allocation exercises the cron's keep-kernel fallback without collecting host telemetry.
function pmssDynamicIopsShadowCycle($users, $policy, &$allocation, ...$args) {
    $cap = $GLOBALS['options']['normal']; $allocation = ['read_caps' => $cap === null ? null : ['fixtur01' => $cap]]; return true;
}
// Keep the real held-cap and record policy, replacing only its persistence boundary.
function pmssIntegerSettingRuntimeUserPath($bucket, $user, $runtime) { return '/fixture/runtime/'.$bucket.'/'.$user; }
function pmssIntegerSettingFileRead(...$args) { return $GLOBALS['options']['budget']; }
function pmssIntegerSettingFileParse($path, $parser) { return isset($GLOBALS['state']['files'][$path]) ? $parser($GLOBALS['state']['files'][$path]) : null; }
function pmssRegularFilePathIsReadable($path) { return isset($GLOBALS['state']['files'][$path]); }
function pmssReadRegularFileContents($path) { return $GLOBALS['state']['files'][$path] ?? null; }
function pmssIntegerSettingStorageDirEnsure(...$args) { return true; }
function pmssAtomicWriteFile($path, $value, $mode) { $GLOBALS['state']['files'][$path] = $value; return true; }
function pmssIntegerSettingFileRemove($path) { unset($GLOBALS['state']['files'][$path]); return true; }
function pmssReadLimitStatusPublish(...$args) { $GLOBALS['state']['notices'][] = $args; return true; }
function pmssCgroupIopsCensusPrint(...$args) {}
function pmssRunSnapshotLogTask($script, $env, $path, $callback) {
    $handle = fopen('php://memory', 'w+'); $rc = $callback($handle); rewind($handle);
    $GLOBALS['state']['audits'][] = json_decode(stream_get_contents($handle), true); fclose($handle); return $rc;
}
PHP;
        // Inline the production helpers so their file operations resolve to the intercepted namespace above.
        foreach (['cgroup/directApply.php', 'user/iopsLimit.php'] as $file) {
            $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/'.$file, 'IopsBudgetCronFixture');
        }
        $script .= '$GLOBALS["options"] = '.var_export($options, true).'; $argv = '.var_export($args, true).';';
        $script .= <<<'PHP'
$GLOBALS['state'] = ['kernel' => $GLOBALS['options']['kernel'], 'files' => $GLOBALS['options']['files'],
    'writes' => [], 'audits' => [], 'notices' => []];
ob_start();
register_shutdown_function(static function () { ob_end_clean(); echo json_encode($GLOBALS['state']); });
PHP;
        $source = $this->pmssReadRepoFile('scripts/cron/cgroupIopsLimitApply.php');
        $source = substr($source, strpos($source, '<?php') + 5);
        $source = str_replace(['declare(strict_types=1);', '__DIR__'], ['', var_export($this->pmssRepoPath('scripts/cron'), true)], $source);
        $script .= 'eval('.var_export('namespace IopsBudgetCronFixture; use \\Throwable;'.$source, true).');';
        return $this->pmssRunInlinePhpJson($script, ['PMSS_DRY_RUN' => '0']);
    }

    public function testHeldCapSurvivesRepeatedCronRunsAndNormalCapChanges(): void
    {
        $first = $this->cycle();
        $this->assertSame([[self::READ_PATH, '8:0 30']], $first['writes']);
        $this->assertSame('monthly-iops-budget', $first['audits'][0]['source']);
        $this->assertSame('30', $first['files'][self::RECORD]);
        foreach ([['enforce', 5000], ['enforce', null], ['off', null]] as [$mode, $normal]) {
            $next = $this->cycle(['kernel' => $first['kernel'], 'files' => $first['files'], 'mode' => $mode, 'normal' => $normal]);
            $this->assertSame('8:0 30', $next['kernel'][self::READ_PATH]);
            $this->assertSame([], $next['writes']);
            $this->assertSame([], $next['audits']);
        }
    }

    public function testHoldNeverRaisesATighterNormalReadCap(): void
    {
        $row = $this->cycle(['normal' => 20]);
        $this->assertSame([[self::READ_PATH, '8:0 20']], $row['writes']);
        $this->assertFalse(isset($row['files'][self::RECORD]));
        $this->assertSame([], $row['notices']);
    }

    public function testMarkerRemovalRestoresNormalCapOrClearsHoldDuringFallback(): void
    {
        $held = $this->cycle();
        unset($held['files'][self::MARKER]);
        foreach ([2000 => '8:0 2000', 0 => '8:0 0'] as $normal => $expected) {
            $row = $this->cycle(['kernel' => $held['kernel'], 'files' => $held['files'], 'normal' => $normal ?: null]);
            $this->assertSame([[self::READ_PATH, $expected]], $row['writes']);
            $this->assertFalse(isset($row['files'][self::RECORD]));
            if ($normal === 0) $this->assertSame('monthly-iops-budget-release', $row['audits'][0]['source']);
        }
    }

    public function testUnsafeKernelNeverAcquiresABudgetCap(): void
    {
        $row = $this->cycle(['mode' => 'off', 'ready' => false, 'normal' => null]);
        $this->assertSame([[self::READ_PATH, '8:0 0']], $row['writes']);
        $this->assertFalse(isset($row['files'][self::RECORD]));
        $this->assertSame([], $row['notices']);
    }

    public function testReleaseDuringFallbackLeavesAnUnrelatedKernelCapAlone(): void
    {
        $row = $this->cycle(['normal' => null, 'kernel' => [self::READ_PATH => '8:0 20'], 'files' => [self::RECORD => '30']]);
        $this->assertSame('8:0 20', $row['kernel'][self::READ_PATH]);
        $this->assertSame([], $row['writes']);
        $this->assertFalse(isset($row['files'][self::RECORD]));
    }

    public function testRemovedBudgetReleasesTheCapEvenBeforeTheMarkerIsRemoved(): void
    {
        $held = $this->cycle();
        $row = $this->cycle(['normal' => null, 'budget' => 0, 'kernel' => $held['kernel'], 'files' => $held['files']]);
        $this->assertSame([[self::READ_PATH, '8:0 0']], $row['writes']);
        $this->assertFalse(isset($row['files'][self::RECORD]));
    }

    public function testPreviewLeavesKernelRecordsAndNoticesUntouched(): void
    {
        foreach ([['--dry-run'], ['--census'], ['--census', '--dry-run']] as $args) {
            $row = $this->cycle([], $args);
            $this->assertSame('8:0 2000', $row['kernel'][self::READ_PATH]);
            $this->assertSame([self::MARKER => '1700000000'], $row['files']);
            foreach (['writes', 'audits', 'notices'] as $key) $this->assertSame([], $row[$key]);
        }
    }
}
