<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/mdadmCheckarray.php';

class MdadmCheckarrayTest extends TestCase
{
    /** @var string */
    private $fixtureRoot;

    protected function pmssTempDirFixtureArguments(): array { return ['fixtureRoot', 'pmss-mdadm-checkarray-', 0700]; }

    public function testPlanKeepsOnlyHealthyArrays(): void
    {
        $mdstat = $this->pmssWriteRelativeFile($this->fixtureRoot, 'mdstat', "Personalities : [raid1]\n".
            "md0 : active raid1 sda1[0] sdb1[1] 1047552 blocks [2/2] [UU]\n".
            "md1 : active raid1 sdc1[0] sdd1[1] 1047552 blocks [2/1] [U_]\n", 0700);
        $this->pmssWriteRelativeFile($this->fixtureRoot, 'sys/block/md0/md/degraded', "0\n", 0700);
        $this->pmssWriteRelativeFile($this->fixtureRoot, 'sys/block/md1/md/degraded', "1\n", 0700);

        $plan = \pmssMdadmCheckarrayPlan($mdstat, $this->fixtureRoot.'/sys/block');

        $this->pmssAssertArraySubsetSame(['healthy' => ['md0'], 'degraded' => ['md1'], 'unknown' => []], $plan);
        $this->assertFalse($plan['fallback_all']);
    }

    public function testArrayNamesRequireAWholeMdDeviceName(): void
    {
        foreach (['md0', 'md1', 'md123', 'md000'] as $array) {
            $this->assertTrue(\pmssMdadmCheckarrayArrayNameIsSafe($array));
        }
        foreach (['', 'md', 'md-1', 'md/0', '--all', "md0\n", "md0\r", "md0\0", 'md0 extra'] as $array) {
            $this->assertFalse(\pmssMdadmCheckarrayArrayNameIsSafe($array));
        }
    }

    public function testMalformedReadPathsKeepExistingFailureResults(): void
    {
        $path = $this->pmssWriteRelativeFile($this->fixtureRoot, 'integer', "365\n", 0700);
        foreach (['', "\0", $path."\0", "\0".$path, $path."\0ignored"] as $invalid) {
            $this->pmssAssertNoPhpWarnings(function () use ($invalid): void {
                $this->assertSame(null, \pmssMdadmCheckarrayReadInteger($invalid));
                $plan = \pmssMdadmCheckarrayPlan($invalid, $this->fixtureRoot);
                $this->assertSame([
                    'fallback_all' => true, 'reason' => 'mdstat_unreadable',
                    'healthy' => [], 'degraded' => [], 'unknown' => [],
                ], $plan);
            });
        }
        $this->assertSame(365, \pmssMdadmCheckarrayReadInteger($path));
        $this->assertSame("365\n", file_get_contents($path));
    }

    public function testMalformedSysfsRootsRetainMdstatFallbackAndDoNotWrite(): void
    {
        $this->pmssWriteRelativeFile($this->fixtureRoot, 'sys/block/md0/md/degraded', "0\n", 0700);
        $root = $this->fixtureRoot.'/sys/block';
        $sync = $this->pmssWriteRelativeFile($root, 'md0/md/sync_action', "check\n", 0700);
        foreach (["\0", $root."\0", "\0".$root, $root."\0ignored", $root."/\0/"] as $invalid) {
            $this->pmssAssertNoPhpWarnings(function () use ($invalid): void {
                foreach (['[2/2] [UU]' => false, '[2/1] [U_]' => true, '' => null] as $detail => $expected) {
                    $this->assertSame($expected, \pmssMdadmCheckarrayEntryDegradedState(
                        ['array' => 'md0', 'detail' => $detail], $invalid
                    ));
                }
                $this->assertFalse(\pmssMdadmCheckarrayRequestIdle('md0', $invalid));
            });
            $this->assertSame("check\n", file_get_contents($sync));
        }
        $this->assertSame(false, \pmssMdadmCheckarrayEntryDegradedState(
            ['array' => 'md0', 'detail' => '[2/1] [U_]'], $root
        ));
        $this->assertTrue(\pmssMdadmCheckarrayRequestIdle('md0', $root));
        $this->assertSame("idle\n", file_get_contents($sync));
    }

    public function testMalformedCommandRequestsNeverLaunchTheHelper(): void
    {
        $stub = $this->writeCheckarrayStub();
        $this->pmssWithEnv(['PMSS_MDADM_CHECKARRAY_STUB_LOG' => $this->fixtureRoot.'/checkarray.log'], function () use ($stub): void {
            $requests = [['', []], ["\0", []], [$stub."\0ignored", []]];
            foreach (['', '--all', 'md/0', "md0\n", "md0\0", 0, null, [], new \stdClass()] as $array) {
                // A valid prefix must not launch when any later member is invalid.
                $requests[] = [$stub, ['md0', $array]];
            }
            foreach ($requests as [$binary, $arrays]) {
                $this->pmssAssertNoPhpWarnings(function () use ($binary, $arrays): void {
                    $this->assertSame(1, \pmssMdadmCheckarrayRunCommand($binary, $arrays));
                });
                $this->assertFalse(file_exists($this->fixtureRoot.'/checkarray.log'));
            }
            foreach ([['md0', 'md12'], []] as $arrays) {
                $this->assertSame(0, \pmssMdadmCheckarrayRunCommand($stub, $arrays));
            }
            $this->assertSame("--idle --quiet md0 md12\n--idle --quiet --all\n", $this->pmssReadFileOrEmpty($this->fixtureRoot.'/checkarray.log'));
        });
    }

    public function testMalformedStatePathsKeepSuccessfulCommandStatus(): void
    {
        $stub = $this->writeCheckarrayStub();
        $path = $this->fixtureRoot.'/missing/state';
        $environment = ['PMSS_MDADM_CHECKARRAY_STUB_LOG' => $this->fixtureRoot.'/checkarray.log'];
        foreach (['', "\0", $path."\0", "\0".$path, $path."\0ignored"] as $invalid) {
            $this->pmssAssertNoPhpWarnings(function () use ($stub, $invalid, $environment): void {
                [$rc, $output] = $this->pmssCaptureStdout(static function () use ($stub, $invalid): int {
                    return \pmssMdadmCheckarrayRunAndRemember($stub, ['md0'], $invalid);
                }, $environment);
                $this->assertSame(0, $rc);
                $this->assertStringContainsString('unable to write last-check state', $output);
            });
            $this->assertFalse(file_exists(dirname($path)), 'Invalid state paths must not create parents');
        }
        $this->assertSame(str_repeat("--idle --quiet md0\n", 5), $this->pmssReadFileOrEmpty($this->fixtureRoot.'/checkarray.log'));
    }

    public function testPlanFallsBackToAllWhenMdstatIsUnreadable(): void
    {
        $plan = \pmssMdadmCheckarrayPlan($this->fixtureRoot.'/missing-mdstat', $this->fixtureRoot.'/sys/block');

        $this->assertTrue($plan['fallback_all']);
        $this->assertSame('mdstat_unreadable', $plan['reason']);
    }

    public function testDegradedStatePreservesSourcePrecedenceAndUnknowns(): void
    {
        foreach ([
            [null, '[2/2] [UU]', false], [null, '[2/1] [U_]', true],
            ['', '[2/2] [UU]', false], ['invalid', '[2/1] [U_]', true],
            ['-1', '[2/2] [UU]', false], ['0', '[2/1] [U_]', false],
            ['1', '[2/2] [UU]', true], [' 0 ', '', false],
            [null, '', null], ['invalid', 'unsupported', null],
            [null, '[2/1] [UU]', true],
        ] as $index => [$sysfs, $detail, $expected]) {
            $array = 'md'.$index;
            if ($sysfs !== null) $this->pmssWriteRelativeFile($this->fixtureRoot, 'sys/block/'.$array.'/md/degraded', $sysfs."\n", 0700);
            $this->assertSame($expected, \pmssMdadmCheckarrayEntryDegradedState(
                ['array' => $array, 'detail' => $detail], $this->fixtureRoot.'/sys/block/'
            ));
        }
        $this->assertSame(null, \pmssMdadmCheckarrayEntryDegradedState([], $this->fixtureRoot));
    }

    /** Numeric boundaries for scripts/lib/storageHealth/raidParse.php. */
    public function testMdstatMemberCountsRejectOverflowWithoutHidingMissingMembers(): void
    {
        $limit = (string) PHP_INT_MAX;
        foreach ([
            [$limit.'0', $limit.'1'], [$limit.'0', $limit.'0'],
            [$limit.'0', '2'], ['2', $limit.'0'],
            ['000'.$limit.'0', '000'.$limit.'1'], [str_repeat('9', 256), '1'],
        ] as [$expected, $active]) {
            $this->assertSame(null, \pmssStorageHealthRaidDetailDegradedState('['.$expected.'/'.$active.'] [UU]'));
            // The member bitmap remains independent evidence of degradation.
            $this->assertSame(true, \pmssStorageHealthRaidDetailDegradedState('['.$expected.'/'.$active.'] [U_]'));
        }
        foreach ([
            ['[0/0] [UU]', false], ['[2/2] [UU]', false], ['[2/1] [UU]', true],
            ['[0002/02] [UU]', false], ['[2/2] [U_]', true],
            ['['.$limit.'/'.$limit.'] [UU]', false],
            ['['.$limit.'/'.(PHP_INT_MAX - 1).'] [UU]', true],
            ['[2/x] [UU]', null], ['', null],
        ] as [$detail, $expected]) {
            $this->assertSame($expected, \pmssStorageHealthRaidDetailDegradedState($detail));
        }
    }

    public function testOverflowedMdstatCountsStayUnknownWithoutChangingSysfsPrecedence(): void
    {
        $overflow = (string) PHP_INT_MAX.'0';
        $detail = '['.$overflow.'/'.$overflow.'] [UU]';
        $mdstat = $this->pmssWriteRelativeFile($this->fixtureRoot, 'mdstat',
            'md0 : active raid1 '.$detail."\nmd1 : active raid1 [2/2] [UU]\n".
            'md2 : active raid1 ['.$overflow."/1] [U_]\n", 0700);
        // Missing and malformed sysfs values must not turn corrupt mdstat into healthy state.
        foreach ([null, 'invalid', '0', '1'] as $sysfs) {
            if ($sysfs !== null) $this->pmssWriteRelativeFile($this->fixtureRoot, 'sys/block/md0/md/degraded', $sysfs."\n", 0700);
            $plan = \pmssMdadmCheckarrayPlan($mdstat, $this->fixtureRoot.'/sys/block');
            $this->assertFalse($plan['fallback_all']);
            $this->pmssAssertArraySubsetSame(['reason' => '',
                'healthy' => $sysfs === '0' ? ['md0', 'md1'] : ['md1'],
                'degraded' => $sysfs === '1' ? ['md0', 'md2'] : ['md2'],
                'unknown' => $sysfs === null || $sysfs === 'invalid' ? ['md0'] : []], $plan);
        }
    }

    public function testMainSkipsOverflowedMdstatCountsWithoutLaunchingCheckarray(): void
    {
        $limit = (string) PHP_INT_MAX;
        $mdstat = $this->pmssWriteRelativeFile($this->fixtureRoot, 'mdstat',
            'md0 : active raid1 ['.$limit.'0/'.$limit."1] [UU]\n", 0700);
        $result = $this->runCommand($mdstat, $this->writeCheckarrayStub());

        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsString('skipping md0 (state unknown)', $result['output']);
        $this->assertSame('', $this->pmssReadFileOrEmpty($this->fixtureRoot.'/checkarray.log'));
        $this->assertFalse(file_exists($this->fixtureRoot.'/state/mdadm-checkarray-last'));
    }

    public function testPlanFallsBackWhenMdstatLooksUnsupported(): void
    {
        $mdstat = $this->pmssWriteRelativeFile($this->fixtureRoot, 'mdstat', "md0 : unexpected format without raid level\n", 0700);

        $plan = \pmssMdadmCheckarrayPlan($mdstat, $this->fixtureRoot.'/sys/block');

        $this->assertTrue($plan['fallback_all']);
        $this->assertSame('mdstat_parse_empty', $plan['reason']);
    }

    public function testUnknownArrayStateIsSkippedByMain(): void
    {
        $mdstat = $this->pmssWriteRelativeFile($this->fixtureRoot, 'mdstat', "md0 : active raid1 sda1[0] sdb1[1] 1047552 blocks\n", 0700);
        $stub = $this->writeCheckarrayStub();

        $result = $this->runCommand($mdstat, $stub);

        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsString('skipping md0 (state unknown)', $result['output']);
        $this->assertSame('', $this->pmssReadFileOrEmpty($this->fixtureRoot.'/checkarray.log'));
    }

    public function testMainChecksHealthyArraysAndSkipsDegradedOnes(): void
    {
        $mdstat = $this->pmssWriteRelativeFile($this->fixtureRoot, 'mdstat', "md0 : active raid1 sda1[0] sdb1[1] 1047552 blocks [2/2] [UU]\n".
            "md1 : active raid1 sdc1[0] sdd1[1] 1047552 blocks [2/1] [U_]\n", 0700);
        $this->pmssWriteRelativeFile($this->fixtureRoot, 'sys/block/md0/md/degraded', "0\n", 0700);
        $this->pmssWriteRelativeFile($this->fixtureRoot, 'sys/block/md1/md/degraded', "1\n", 0700);
        $syncTarget = $this->fixtureRoot.'/sys/block/md1/md/sync_action';
        $this->pmssWriteFile($syncTarget, "check\n", 0700);
        $stub = $this->writeCheckarrayStub();

        $result = $this->runCommand($mdstat, $stub);

        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsAllStrings(['skipping md1 (degraded); requested sync_action=idle',
            'checking non-degraded arrays: md0'], $result['output']);
        $this->assertSame("--idle --quiet md0\n", $this->pmssReadFileOrEmpty($this->fixtureRoot.'/checkarray.log'));
        $this->assertSame("idle\n", (string) file_get_contents($syncTarget));
    }

    public function testMainFallsBackToAllOnTotalEnumerationFailure(): void
    {
        $stub = $this->writeCheckarrayStub();

        $result = $this->runCommand($this->fixtureRoot.'/missing-mdstat', $stub);

        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsString('preserving checkarray --all behavior', $result['output']);
        $this->assertSame("--idle --quiet --all\n", $this->pmssReadFileOrEmpty($this->fixtureRoot.'/checkarray.log'));
        $this->assertTrue(is_file($this->fixtureRoot.'/state/mdadm-checkarray-last'));
    }

    public function testMinimumIntervalBoundaries(): void
    {
        $now = 2000000000;
        $this->assertFalse(\pmssMdadmCheckarrayWithinMinDays(0, $now - 1, $now));
        $this->assertFalse(\pmssMdadmCheckarrayWithinMinDays(365, null, $now));
        $this->assertTrue(\pmssMdadmCheckarrayWithinMinDays(365, $now - 365 * 86400 + 1, $now));
        $this->assertFalse(\pmssMdadmCheckarrayWithinMinDays(365, $now - 365 * 86400, $now));
        $this->assertFalse(\pmssMdadmCheckarrayWithinMinDays(365, $now - 365 * 86400 - 1, $now));
    }

    public function testMainSkipsRecentCheckWithinHostMinimum(): void
    {
        $mdstat = $this->pmssWriteRelativeFile($this->fixtureRoot, 'mdstat', "md0 : active raid1 sda1[0] sdb1[1] 1047552 blocks [2/2] [UU]\n", 0700);
        $this->pmssWriteFile($this->fixtureRoot.'/min-days', "365\n");
        $lastStarted = time() - 10;
        $this->pmssWriteFile($this->fixtureRoot.'/state/mdadm-checkarray-last', (string) $lastStarted."\n", 0700);

        $result = $this->runCommand($mdstat, $this->writeCheckarrayStub());

        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsString('skipping: last check '.date('c', $lastStarted).' is within the 365-day host minimum', $result['output']);
        $this->assertSame('', $this->pmssReadFileOrEmpty($this->fixtureRoot.'/checkarray.log'));
    }

    public function testMainRecordsFirstCheckWithHostMinimum(): void
    {
        $mdstat = $this->pmssWriteRelativeFile($this->fixtureRoot, 'mdstat', "md0 : active raid1 sda1[0] sdb1[1] 1047552 blocks [2/2] [UU]\n", 0700);
        $this->pmssWriteFile($this->fixtureRoot.'/min-days', "365\n");
        $before = time();

        $result = $this->runCommand($mdstat, $this->writeCheckarrayStub());

        $this->assertSame(0, $result['rc']);
        $this->assertSame("--idle --quiet md0\n", $this->pmssReadFileOrEmpty($this->fixtureRoot.'/checkarray.log'));
        $lastStarted = (int) trim((string) file_get_contents($this->fixtureRoot.'/state/mdadm-checkarray-last'));
        $this->assertTrue($lastStarted >= $before && $lastStarted <= time());
    }

    public function testMalformedHostMinimumDoesNotSkip(): void
    {
        $mdstat = $this->pmssWriteRelativeFile($this->fixtureRoot, 'mdstat', "md0 : active raid1 sda1[0] sdb1[1] 1047552 blocks [2/2] [UU]\n", 0700);
        $this->pmssWriteFile($this->fixtureRoot.'/min-days', "365 days\n");
        $this->pmssWriteFile($this->fixtureRoot.'/state/mdadm-checkarray-last', (string) time()."\n", 0700);

        $result = $this->runCommand($mdstat, $this->writeCheckarrayStub());

        $this->assertSame(0, $result['rc']);
        $this->assertSame("--idle --quiet md0\n", $this->pmssReadFileOrEmpty($this->fixtureRoot.'/checkarray.log'));
    }

    public function testFailedCheckDoesNotAdvanceState(): void
    {
        $mdstat = $this->pmssWriteRelativeFile($this->fixtureRoot, 'mdstat', "md0 : active raid1 sda1[0] sdb1[1] 1047552 blocks [2/2] [UU]\n", 0700);

        $result = $this->runCommand($mdstat, $this->writeCheckarrayStub(7));

        $this->assertSame(7, $result['rc']);
        $this->assertFalse(is_file($this->fixtureRoot.'/state/mdadm-checkarray-last'));
    }

    public function testStateWriteFailureKeepsSuccessfulExitCode(): void
    {
        $mdstat = $this->pmssWriteRelativeFile($this->fixtureRoot, 'mdstat', "md0 : active raid1 sda1[0] sdb1[1] 1047552 blocks [2/2] [UU]\n", 0700);
        $this->pmssEnsureDir($this->fixtureRoot.'/state/mdadm-checkarray-last', 0700);

        $result = $this->runCommand($mdstat, $this->writeCheckarrayStub());

        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsString('unable to write last-check state', $result['output']);
        $this->assertSame("--idle --quiet md0\n", $this->pmssReadFileOrEmpty($this->fixtureRoot.'/checkarray.log'));
    }

    public function testIdleRequestRequiresCompleteWrite(): void
    {
        stream_wrapper_register('pmssmdwrite', MdadmCheckarrayWriteStream::class);
        try {
            foreach ([0 => false, 2 => false, 5 => true] as $limit => $expected) {
                MdadmCheckarrayWriteStream::$limit = $limit;
                MdadmCheckarrayWriteStream::$written = '';
                $this->assertSame($expected, \pmssMdadmCheckarrayRequestIdle('md0', 'pmssmdwrite://sys'));
                $this->assertSame(substr("idle\n", 0, $limit), MdadmCheckarrayWriteStream::$written);
            }
            $this->assertFalse(\pmssMdadmCheckarrayRequestIdle('md/0', 'pmssmdwrite://sys'));
        } finally {
            stream_wrapper_unregister('pmssmdwrite');
        }
    }

    public function testRootCronUsesGuardWithoutChangingQuarterlyGate(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('etc/seedbox/config/root.cron',
            ['/scripts/cron/mdadmCheckarray.php', '[ $(date +\%d) -le 7 ]',
                '$(($(date +\%-m) \% 3)) -eq $((H \% 3))'], ['/usr/share/mdadm/checkarray'.' --cron --all']);
    }

    private function writeCheckarrayStub(int $exitCode = 0): string
    {
        $path = $this->fixtureRoot.'/checkarray';
        $body = "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >> \"\$PMSS_MDADM_CHECKARRAY_STUB_LOG\"\nexit ".$exitCode."\n";
        $this->pmssWriteExecutableFile($path, $body);
        return $path;
    }

    /** @return array{rc:int,output:string,lines:array<int,string>} */
    private function runCommand(string $mdstatPath, string $stub): array
    {
        return $this->pmssExecShellCommand(
            'php '.escapeshellarg($this->pmssRepoPath('scripts/cron/mdadmCheckarray.php')),
            [
                'PMSS_MDADM_CHECKARRAY_BIN' => $stub,
                'PMSS_MDADM_CHECKARRAY_MDSTAT_PATH' => $mdstatPath,
                'PMSS_MDADM_CHECKARRAY_SYS_BLOCK_ROOT' => $this->fixtureRoot.'/sys/block',
                'PMSS_MDADM_CHECKARRAY_STUB_LOG' => $this->fixtureRoot.'/checkarray.log',
                'PMSS_MDADM_CHECKARRAY_MIN_DAYS_PATH' => $this->fixtureRoot.'/min-days',
                'PMSS_MDADM_CHECKARRAY_STATE_PATH' => $this->fixtureRoot.'/state/mdadm-checkarray-last',
            ]
        );
    }
}

/** Simulate a sysfs command accepting only a bounded number of bytes. */
class MdadmCheckarrayWriteStream
{
    public static $limit = 0;
    public static $written = '';

    public function url_stat(): array
    {
        return ['mode' => 0100000, 2 => 0100000];
    }

    public function stream_open(): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        $bytes = min(strlen($data), self::$limit - strlen(self::$written));
        self::$written .= substr($data, 0, $bytes);
        return $bytes;
    }
}
