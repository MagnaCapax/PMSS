<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/storageHealth.php';

/**
 * The SMART probe's arguments must be valid smartctl grammar. The other smart tests stub smartctl's OUTPUT, so an
 * invalid flag never surfaced there — production ran `-n standby,now`, smartctl 7.3 rejected it before opening the
 * device, and every SATA entry parsed as UNKNOWN. This test captures the ARGUMENTS the probe passes.
 */
class StorageHealthSnapshotSmartProbeArgsTest extends TestCase
{
    public function testSmartProbeArgumentsAreValidSmartctlGrammar(): void
    {
        $device = $this->pmssMakeReadableTempPath('pmss-smart-args-', 'dev-');
        $argFile = tempnam(sys_get_temp_dir(), 'pmss-smart-argv-');
        $stub = $this->pmssMakeExecutableStub('smartctl', "#!/bin/sh\nprintf '%s\\n' \"\$@\" > ".escapeshellarg($argFile)."\nprintf 'SMART overall-health self-assessment test result: PASSED\\n'\n", 'pmss-smart-args-bin-');

        $this->pmssWithEnv(['PATH' => $stub], function () use ($device): void {
            \pmssStorageHealthSnapshotSmart(['path' => $device, 'kname' => 'sdq'], [], '2025-01-01T00:00:00+00:00');
        });

        $argv = array_values(array_filter(explode("\n", (string) file_get_contents($argFile)), 'strlen'));
        @unlink($argFile);

        $nIndex = array_search('-n', $argv, true);
        $this->assertTrue($nIndex !== false, 'probe must pass -n (never wake a standby drive)');
        $powerMode = (string) ($argv[(int) $nIndex + 1] ?? '');
        // smartctl(8): -n POWERMODE[,STATUS[,STATUS2]] with POWERMODE in never|sleep|standby|idle and numeric STATUS.
        $this->assertMatches('/^(never|sleep|standby|idle)(,\d+){0,2}$/', $powerMode, 'smartctl -n argument must be valid grammar (was: '.$powerMode.')');
        $this->assertSame('standby', $powerMode);
        foreach (['-H', '-A', '-i'] as $flag) {
            $this->assertTrue(in_array($flag, $argv, true), "probe must pass {$flag}");
        }
        $this->assertSame($device, end($argv), 'device path must be the last argument');
    }
}
