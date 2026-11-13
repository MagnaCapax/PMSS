<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/storageHealth.php';
require_once dirname(__DIR__, 2).'/storageHealth/reportTable.php';

/**
 * Bitmap acquisition, interpretation and findings stay on their existing boundaries:
 * scripts/lib/storageHealth/raid.php, scripts/lib/storageHealth/raidParse.php,
 * scripts/lib/storageHealth/deviceMetrics.php and scripts/lib/storageHealth/reportTable.php.
 */
final class StorageHealthRaidBitmapTest extends TestCase
{
    public function testDetailParserDistinguishesPresentAbsentAndUnknown(): void
    {
        $base = "/dev/md1:\n    Raid Level : raid5\n    State : clean\n";
        foreach ([
            [$base, false], [$base."Consistency Policy : resync\n", false],
            [$base."Intent Bitmap : Internal\n", true],
            [$base."Intent Bitmap : Internal(Clustered)\n", true],
            [$base."Intent Bitmap : /fixture/bitmap\n", true],
            [str_replace("\n", "\r\n", $base."Intent Bitmap : Internal\n"), true],
            ['', null], ['unrecognized output', null], ["Intent Bitmap : Internal\n", null],
            ["Raid Level : raid5\n", null], [$base."Intent Bitmap : \n", null],
            [$base."\0Intent Bitmap : Internal\n", null],
        ] as [$detail, $expected]) {
            $this->assertSame($expected, \pmssStorageHealthRaidBitmapParse($detail));
        }
    }

    public function testSnapshotProbesEachArrayAndReportsMissingBitmap(): void
    {
        $arguments = $this->pmssMakeTempFile('pmss-raid-bitmap-argv-');
        $mdstat = $this->pmssWriteFile($this->pmssMakeTempFile('pmss-raid-bitmap-mdstat-'), "md1 : active raid5 sda1[0] sdb1[1] [2/2] [UU]\n".
            "md2 : active raid1 sdc1[0] sdd1[1] [2/2] [UU]\n");
        $stub = $this->pmssMakeExecutableStub('mdadm', "#!/bin/sh\nprintf '%s\\n' \"\$LC_ALL \$*\" >> ".escapeshellarg($arguments)."\n".
            "printf 'Raid Level : raid5\\nState : clean\\n'\n".
            "if [ \"\$2\" = /dev/md2 ]; then printf 'Intent Bitmap : Internal\\n'; fi\n", 'pmss-raid-bitmap-bin-');
        $this->pmssWithEnv(['PATH' => $stub], function () use ($mdstat): void {
            $entries = \pmssStorageHealthSnapshotRaid('fixture', $mdstat);
            $this->assertSame(2, count($entries));
            $this->pmssAssertArraySubsetSame(['array' => 'md1', 'write_intent_bitmap' => false,
                'flags' => ['no_write_intent_bitmap'], 'severity' => 'warn', 'ok' => false], $entries[0]);
            $this->assertStringContainsString('an unclean stop will force a full resync', $entries[0]['message']);
            $this->pmssAssertArraySubsetSame(['array' => 'md2', 'write_intent_bitmap' => true,
                'flags' => [], 'severity' => 'ok', 'ok' => true], $entries[1]);
            $this->assertFalse(isset($entries[1]['message']));
        });
        $this->assertSame("C --detail /dev/md1\nC --detail /dev/md2\n", file_get_contents($arguments));
    }

    public function testFailedProbesDiscardEvenHealthyLookingPartialOutput(): void
    {
        $mdstat = $this->pmssWriteFile($this->pmssMakeTempFile('pmss-raid-bitmap-failed-'), "md1 : active raid5 sda1[0] [2/2] [UU]\n");
        // Include timeout, forced termination, lock contention and missing-command results.
        foreach ([1, 2, 75, 124, 126, 127, 137, 255] as $rc) {
            $stub = $this->pmssMakeExecutableStub('mdadm', "#!/bin/sh\nprintf 'Raid Level : raid5\\nState : clean\\nIntent Bitmap : Internal\\n'\nexit ".$rc."\n", 'pmss-raid-failed-bin-');
            $this->pmssWithEnv(['PATH' => $stub], function () use ($mdstat): void {
                $entry = \pmssStorageHealthSnapshotRaid('fixture', $mdstat)[0];
                $this->pmssAssertArraySubsetSame(['write_intent_bitmap' => null,
                    'flags' => ['write_intent_bitmap_unknown'], 'severity' => 'warn', 'ok' => false], $entry);
            });
        }
    }

    public function testMissingToolEmptyAndMalformedSuccessfulProbesRemainUnknown(): void
    {
        $mdstat = $this->pmssWriteFile($this->pmssMakeTempFile('pmss-raid-bitmap-unknown-'), "md1 : active raid1 sda1[0] [2/2] [UU]\n");
        $paths = [$this->pmssMakeTempDir('pmss-raid-no-mdadm-')];
        foreach (['', 'unrecognized output', 'Intent Bitmap : Internal'] as $output) {
            $paths[] = $this->pmssMakeExecutableStub('mdadm', "#!/bin/sh\nprintf '%s\\n' ".escapeshellarg($output)."\n", 'pmss-raid-unknown-bin-');
        }
        foreach ($paths as $path) {
            $this->pmssWithEnv(['PATH' => $path], function () use ($mdstat): void {
                $entry = \pmssStorageHealthSnapshotRaid('fixture', $mdstat)[0];
                $this->pmssAssertArraySubsetSame(['write_intent_bitmap' => null,
                    'flags' => ['write_intent_bitmap_unknown'], 'severity' => 'warn'], $entry);
                $this->assertStringContainsString('state unknown', $entry['message']);
            });
        }
    }

    public function testBitmapFindingsPreserveDegradationAndRepeatedRebuildFlags(): void
    {
        foreach ([[], ['degraded'], ['rebuild_in_progress', 'rebuild_in_progress']] as $flags) {
            foreach ([true, false, null] as $bitmap) {
                $entry = \pmssStorageHealthRaidBitmapApply(['flags' => $flags], $bitmap);
                $expected = $flags;
                if ($bitmap !== true) $expected[] = $bitmap === false ? 'no_write_intent_bitmap' : 'write_intent_bitmap_unknown';
                $this->assertSame($expected, $entry['flags']);
                $severity = in_array('degraded', $flags, true) ? 'fail' : ($expected === [] ? 'ok' : 'warn');
                $this->pmssAssertArraySubsetSame(['severity' => $severity, 'ok' => $severity === 'ok'], $entry);
            }
        }
    }

    public function testBitmapWarningsDoNotClaimCurrentPerformanceLossOrDegradation(): void
    {
        foreach ([false, null] as $bitmap) {
            $entry = \pmssStorageHealthRaidBitmapApply(['array' => 'md1', 'flags' => []], $bitmap);
            $this->assertSame(null, \pmssStorageHealthPerformanceStatus([$entry]));
            $this->assertSame('', \pmssStorageHealthHomeRaidNoticeHtmlBuild($entry));
            foreach (['degraded', 'rebuild_in_progress'] as $flag) {
                $entry = \pmssStorageHealthRaidBitmapApply(['array' => 'md1', 'flags' => [$flag]], $bitmap);
                $this->assertSame('performance_limited', \pmssStorageHealthPerformanceStatus([$entry])['status']);
            }
        }
        // Legacy warning/failure payloads still use the original performance fallback.
        foreach ([['severity' => 'warn', 'flags' => []], ['severity' => 'warn', 'flags' => ['other_warning']],
            ['severity' => 'fail', 'flags' => ['no_write_intent_bitmap']]] as $entry) {
            $this->assertSame('performance_limited', \pmssStorageHealthPerformanceStatus([$entry])['status']);
        }
    }

    public function testOperatorTableExplainsTheRecoveryRisk(): void
    {
        $entry = \pmssStorageHealthRaidBitmapApply(['array' => 'md1', 'flags' => []], false);
        ob_start();
        try {
            \pmssStorageHealthPrintTable([], [$entry], 'fixture', '/fixture/snapshot.jsonl');
            $this->assertStringContainsString($entry['message'], ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }
}
