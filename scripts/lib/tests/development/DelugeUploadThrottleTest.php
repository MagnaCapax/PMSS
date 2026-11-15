<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/deluge.php';

/** Regression coverage for #1263; all processes and paths are fixtures. */
final class DelugeUploadThrottleTest extends TestCase
{
    /** Run the actual controllers with memory-backed I/O, independent of the testing UID. */
    private function observe(array $cases): array
    {
        $script = 'namespace DelugeThrottleFixture; require '.var_export($this->pmssRepoPath('scripts/lib/user/deluge.php'), true).';';
        $script .= <<<'PHP'
function pmssValidateUsername($user) { return $user === 'fixtur01'; }
function pmssUserWatchdogProcessRunning($user, $process) { return $GLOBALS['case']['running'] ?? false; }
function pmssReadTorrentThrottle($user) { return $GLOBALS['case']['cap'] ?? null; }
function pmssDelugeConfigPath($user) { return '/fixture/core.conf'; }
function pmssReadRegularFileContents($path) { return $GLOBALS['raw']; }
function pmssIntegerSettingFileReadOrNull($path, $parser) { return $GLOBALS['applied']; }
function pmssIntegerSettingRuntimeUserPath($bucket, $user) { return '/fixture/runtime/'.$bucket.'/'.$user; }
function pmssIntegerSettingStorageDirEnsure($path, $mode) {
    $GLOBALS['events'][] = ['directory', $path, $mode]; return $GLOBALS['case']['directory'] ?? true;
}
function pmssIntegerSettingTargetModesPersist($targets, $value) {
    $GLOBALS['events'][] = ['record', $targets, $value];
    if (!($GLOBALS['case']['recordWrite'] ?? true)) return false;
    $GLOBALS['applied'] = $value; return true;
}
function pmssDelugeConfigFileWrite($path, $content, $label) {
    $GLOBALS['events'][] = ['write', $label];
    if (!($GLOBALS['case']['configWrite'] ?? true)) return false;
    $GLOBALS['raw'] = $content; return true;
}
function pmssIntegerSettingFileRemove($path) { $GLOBALS['events'][] = ['remove']; $GLOBALS['applied'] = 0; return true; }
function pmssLogStatus($level, $message, $rc) { $GLOBALS['events'][] = ['log', $level]; }
PHP;
        foreach (['pmssDelugeApplyUploadThrottle', 'pmssDelugeUploadThrottleNeedsUpdate',
            'pmssDelugeUploadThrottleRecordWrite', 'pmssDelugeUploadThrottleRecordPath'] as $name) {
            $script .= \pmssTestingFunctionExport($name, 'DelugeThrottleFixture');
        }
        $script .= <<<'PHP'
$results = [];
foreach (json_decode(getenv('PMSS_TEST_DELUGE_THROTTLE'), true) as $GLOBALS['case']) {
    $GLOBALS['raw'] = $GLOBALS['case']['raw']; $GLOBALS['applied'] = $GLOBALS['case']['applied']; $GLOBALS['events'] = [];
    $user = $GLOBALS['case']['user'] ?? 'fixtur01';
    $needed = pmssDelugeUploadThrottleNeedsUpdate($user);
    $readOnly = $GLOBALS['events'] === [] && $GLOBALS['raw'] === $GLOBALS['case']['raw']
        && $GLOBALS['applied'] === $GLOBALS['case']['applied'];
    $changed = pmssDelugeApplyUploadThrottle($user);
    $results[] = [$needed, $readOnly, $changed, $GLOBALS['events'], $GLOBALS['raw'], $GLOBALS['applied'],
        pmssDelugeUploadThrottleNeedsUpdate($user)];
}
echo json_encode($results);
PHP;
        return $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_DELUGE_THROTTLE' => json_encode($cases)]);
    }

    private function config($value): string
    {
        return '{"file":1,"format":1}'.json_encode([
            'max_upload_speed' => $value, 'max_upload_speed_per_torrent' => 77,
            'download_location' => '/srv/fixture-data',
        ], JSON_PRESERVE_ZERO_FRACTION);
    }

    public function testNumericComparisonAndRecordedCapRemoval(): void
    {
        foreach ([
            [250, 250, 0, null], [250.0, 250, 0, null], [2.5E2, 250, 0, null],
            [249.5, 250, 0, 250], [-1.0, 250, 0, 250], [null, 250, 0, 250],
            ['250', 250, 0, 250], [true, 250, 0, 250], [500, 250, 0, 250], [100, 250, 0, 250],
            [250, null, 0, null], [250.0, 0, 0, null], [-1.0, null, 250, null],
            [250.0, null, 250, -1], [250, 0, 250, -1], [100, null, 250, null],
            [250.5, null, 250, null], ['250', null, 250, null],
            [PHP_INT_MAX, PHP_INT_MAX, 0, null],
            [(float) PHP_INT_MAX, PHP_INT_MAX, 0, PHP_INT_MAX],
        ] as [$current, $cap, $applied, $expected]) {
            $updated = \pmssDelugeUploadThrottleConfig($this->config($current), $cap, $applied);
            if ($expected === null) {
                $this->assertSame(null, $updated);
                continue;
            }
            $decoded = \pmssDelugeConfigDecode($updated);
            $this->assertSame($expected, $decoded['config']['max_upload_speed']);
            $this->assertSame(77, $decoded['config']['max_upload_speed_per_torrent']);
            $this->assertSame('/srv/fixture-data', $decoded['config']['download_location']);
            $this->assertSame(['file' => 1, 'format' => 1], $decoded['meta']);
        }
    }

    public function testMalformedDocumentsAreNeverRewritten(): void
    {
        foreach (['', '{}', '{}{"max_upload_speed":', '{}{"max_upload_speed":1}junk', "{}{}\0"] as $raw) {
            $this->assertSame(null, \pmssDelugeUploadThrottleConfig($raw, 250));
        }
    }

    public function testStoppedCapWriteIsRecordedAndFloatSaveDoesNotRestart(): void
    {
        [$write, $saved] = $this->observe([
            ['raw' => $this->config(-1.0), 'cap' => 250, 'applied' => 0],
            ['raw' => $this->config(250.0), 'cap' => 250, 'applied' => 250],
        ]);
        $this->assertSame([true, true, true], array_slice($write, 0, 3));
        $this->assertSame([['directory', '/fixture/runtime/delugeUploadThrottle', 0700],
            ['record', ['/fixture/runtime/delugeUploadThrottle/fixtur01' => 0600], 250],
            ['write', 'upload throttle']], $write[3]);
        $this->assertSame(250, \pmssDelugeConfigDecode($write[4])['config']['max_upload_speed']);
        $this->assertSame(250, $write[5]);
        $this->assertFalse($write[6]);
        $this->assertSame([false, true, false, [], $this->config(250.0), 250, false], $saved);
    }

    public function testNoCapPreservesCustomerLimitsAndReleasesOnlyRecordedLimits(): void
    {
        foreach ([[250.0, null, false], [250.0, 250, true], [100, 250, false]] as [$value, $applied, $changed]) {
            $original = $this->config($value);
            [$result] = $this->observe([['raw' => $original, 'applied' => $applied ?? 0]]);
            $this->assertSame([$changed, true, $changed], array_slice($result, 0, 3));
            $this->assertSame($changed ? [['write', 'upload throttle'], ['remove']]
                : ($applied === null ? [] : [['remove']]), $result[3]);
            $this->assertSame(0, $result[5]);
            $this->assertSame($changed ? -1 : $value,
                \pmssDelugeConfigDecode($result[4])['config']['max_upload_speed']);
            if (!$changed) $this->assertSame($original, $result[4]);
            $this->assertFalse($result[6]);
        }
    }

    public function testRunningDaemonDefersConfigAndRecordWrites(): void
    {
        $original = $this->config(-1.0);
        [$result] = $this->observe([['raw' => $original, 'cap' => 250, 'applied' => 0, 'running' => true]]);
        $this->assertSame([true, true, false, [], $original, 0, true], $result);
    }

    public function testReadAndPublicationFailuresLeaveConfigurationUntouched(): void
    {
        foreach ([['raw' => null], ['raw' => '{}broken'], ['applied' => null],
            ['directory' => false], ['recordWrite' => false], ['configWrite' => false],
            ['user' => '../fixtur01']] as $failure) {
            $case = $failure + ['raw' => $this->config(-1.0), 'cap' => 250, 'applied' => 0];
            [$result] = $this->observe([$case]);
            $this->assertTrue($result[1]);
            $this->assertFalse($result[2]);
            $this->assertSame($case['raw'], $result[4]);
        }
    }

    public function testSymlinkedAppliedRecordDefersWithoutChangingTheTarget(): void
    {
        $root = $this->pmssMakeTempDir('pmss-deluge-record-link-');
        $this->pmssTrackEnvOverrides(['PMSS_RUNTIME_DIR' => $root.'/runtime', 'PMSS_CONFIG_DIR' => $root.'/config']);
        $this->pmssWriteFile($root.'/config/users/fixtur01.json', '{"uploadThrottleKib":250}');
        $record = $root.'/runtime/delugeUploadThrottle/fixtur01';
        $this->pmssEnsureDir(dirname($record));
        $target = $this->pmssWriteFile($root.'/target', '250');
        $this->pmssCreateSymlinkOrSkip($target, $record);
        $this->assertFalse(\pmssDelugeUploadThrottleNeedsUpdate('fixtur01'));
        $this->assertSame('250', file_get_contents($target));
        $this->assertTrue(is_link($record));
    }

    public function testWatchdogComparesThenStopsThenWrites(): void
    {
        $source = $this->pmssReadRepoFile('scripts/cron/checkDelugeInstances.php');
        $this->assertOrderedStrings(['pmssDelugeUploadThrottleNeedsUpdate($thisUser)',
            'pmssTorrentClientNativeStop($thisUser',
            "pmssUserWatchdogProcessRunning(\$thisUser, 'deluged'))",
            "'pmssDelugeApplyManagedConfig'", "'pmssDelugeApplyUploadThrottle'"], $source);
        $this->assertStringContainsString("return ['deluged' => true, 'deluge-web' => true];", $source);
        $this->pmssAssertRepoFileContainsString('scripts/lib/user/deluge.php',
            'pmssDelugeUploadThrottleRecordWrite($username, $throttle)');
    }
}
