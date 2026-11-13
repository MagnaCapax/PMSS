<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Corrupt text at scripts/lib/storageHealth/smart.php must never prove device health. */
class StorageHealthSmartOutputSafetyTest extends TestCase
{
    public function testCorruptOutputUsesUnavailableResultAndValidTextKeepsItsContract(): void
    {
        // No real device, command lookup, lock or process is used by this fixture.
        $script = 'namespace SmartOutputSafetyFixture; require_once '.
            var_export($this->pmssRepoPath('scripts/lib/storageHealth.php'), true).';';
        $script .= <<<'PHP'
function is_readable($path) { $GLOBALS['events'][] = ['readable', $path]; return true; }
function pmssCommandPath($name) { $GLOBALS['events'][] = ['lookup', $name]; return '/fixture/smartctl'; }
function pmssStorageHealthProbeCommand($kind, $name, $command) {
    $GLOBALS['events'][] = ['probe', $kind, $name, $command];
    return ['command' => $command, 'lock_exit_code' => 75];
}
function pmssCommandCapture($command, $timeout) {
    $GLOBALS['events'][] = ['capture', $command, $timeout];
    return $GLOBALS['response'];
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/storageHealth/smart.php',
            'SmartOutputSafetyFixture');
        $script .= <<<'PHP'
$disk = ['path' => "/dev/fixture disk's data", 'kname' => 'sdq', 'model' => 'Fixture', 'rota' => 1];
$stamp = '2025-01-01T00:00:00+00:00';
$last = ['smart::'.$disk['path'] => ['metrics' => ['udma_crc' => 2, 'pending' => 0]]];
$passed = "SMART Health Status: PASSED\n199 UDMA_CRC_Error_Count 4\n197 Current_Pending_Sector 1\n";
$command = 'smartctl -n standby -H -A -i '.escapeshellarg($disk['path']);
$events = [['readable', $disk['path']], ['lookup', 'smartctl'],
    ['probe', 'smart', 'sdq', $command], ['capture', $command, 25]];
$cases = [];
// Edge, embedded and separate-line NULs in either stream invalidate the whole response.
foreach (["\0", "\0".$passed, $passed."\0", "SMART Health Status: PA\0SSED\n",
    $passed."\0ignored\n", "Device is in STANDBY\n\0"] as $text) {
    $cases[] = [$text, ''];
    $cases[] = [$passed, $text];
}
$invalidCount = count($cases);
// Valid bitmask exits still carry health data; only timeout adds its existing finding.
foreach (['', " \t\r\n", $passed, "SMART Health Status: FAILED\n", "Device is in STANDBY\n"] as $text) {
    $cases[] = [$text, ''];
    $cases[] = ['', $text];
}
$rows = [];
foreach ($cases as $index => [$stdout, $stderr]) {
    foreach ([0, 1, 2, 4, 8, 16, 32, 64, 128, 124, 75] as $rc) {
        $GLOBALS['events'] = [];
        $GLOBALS['response'] = ['rc' => $rc, 'stdout' => $stdout, 'stderr' => $stderr];
        $entry = pmssStorageHealthSnapshotSmart($disk, $last, $stamp);
        if ($index < $invalidCount + 4) {
            $locked = $rc === 75;
            $expected = \pmssStorageHealthEntryFinalize(
                \pmssStorageHealthDeviceEntryBuild('smart', $disk, $stamp, 1),
                [$locked ? 'smartctl_probe_locked' : 'smartctl_empty'],
                $locked ? 'smartctl probe already running' : 'smartctl produced no output');
        } else {
            $expected = \pmssStorageHealthParseSmartctlOutput($stdout."\n".$stderr, $disk,
                ['udma_crc' => 2, 'pending' => 0, 'link_errors' => 2], $stamp);
            if ($rc === 124) $expected = \pmssStorageHealthEntryFinalize($expected,
                array_merge($expected['flags'], ['smartctl_timeout']));
        }
        $rows[] = [$index, $rc, $entry === $expected, $GLOBALS['events'] === $events];
    }
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(242, count($rows));
        foreach ($rows as [$index, $rc, $sameEntry, $sameEvents]) {
            $case = 'output case '.$index.', exit '.$rc;
            $this->assertTrue($sameEntry, $case.': snapshot contract');
            $this->assertTrue($sameEvents, $case.': command and timeout contract');
        }
    }
}
