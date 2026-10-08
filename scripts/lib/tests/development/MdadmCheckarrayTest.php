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
        $mdstat = $this->writeMdstat(
            "Personalities : [raid1]\n".
            "md0 : active raid1 sda1[0] sdb1[1] 1047552 blocks [2/2] [UU]\n".
            "md1 : active raid1 sdc1[0] sdd1[1] 1047552 blocks [2/1] [U_]\n"
        );
        $this->writeSysfsState('md0', '0');
        $this->writeSysfsState('md1', '1');

        $plan = \pmssMdadmCheckarrayPlan($mdstat, $this->fixtureRoot.'/sys/block');

        $this->assertSame(['md0'], $plan['healthy']);
        $this->assertSame(['md1'], $plan['degraded']);
        $this->assertSame([], $plan['unknown']);
        $this->assertFalse($plan['fallback_all']);
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
            if ($sysfs !== null) $this->writeSysfsState($array, $sysfs);
            $this->assertSame($expected, \pmssMdadmCheckarrayEntryDegradedState(
                ['array' => $array, 'detail' => $detail], $this->fixtureRoot.'/sys/block/'
            ));
        }
        $this->assertSame(null, \pmssMdadmCheckarrayEntryDegradedState([], $this->fixtureRoot));
    }

    public function testPlanFallsBackWhenMdstatLooksUnsupported(): void
    {
        $mdstat = $this->writeMdstat("md0 : unexpected format without raid level\n");

        $plan = \pmssMdadmCheckarrayPlan($mdstat, $this->fixtureRoot.'/sys/block');

        $this->assertTrue($plan['fallback_all']);
        $this->assertSame('mdstat_parse_empty', $plan['reason']);
    }

    public function testUnknownArrayStateIsSkippedByMain(): void
    {
        $mdstat = $this->writeMdstat("md0 : active raid1 sda1[0] sdb1[1] 1047552 blocks\n");
        $stub = $this->writeCheckarrayStub();

        $result = $this->runCommand($mdstat, $stub);

        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsString('skipping md0 (state unknown)', $result['output']);
        $this->assertSame('', $this->readStubLog());
    }

    public function testMainChecksHealthyArraysAndSkipsDegradedOnes(): void
    {
        $mdstat = $this->writeMdstat(
            "md0 : active raid1 sda1[0] sdb1[1] 1047552 blocks [2/2] [UU]\n".
            "md1 : active raid1 sdc1[0] sdd1[1] 1047552 blocks [2/1] [U_]\n"
        );
        $this->writeSysfsState('md0', '0');
        $this->writeSysfsState('md1', '1');
        $syncTarget = $this->fixtureRoot.'/sys/block/md1/md/sync_action';
        $this->pmssEnsureDir(dirname($syncTarget), 0700);
        file_put_contents($syncTarget, "check\n");
        $stub = $this->writeCheckarrayStub();

        $result = $this->runCommand($mdstat, $stub);

        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsString('skipping md1 (degraded); requested sync_action=idle', $result['output']);
        $this->assertStringContainsString('checking non-degraded arrays: md0', $result['output']);
        $this->assertSame("--idle --quiet md0\n", $this->readStubLog());
        $this->assertSame("idle\n", (string) file_get_contents($syncTarget));
    }

    public function testMainFallsBackToAllOnTotalEnumerationFailure(): void
    {
        $stub = $this->writeCheckarrayStub();

        $result = $this->runCommand($this->fixtureRoot.'/missing-mdstat', $stub);

        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsString('preserving checkarray --all behavior', $result['output']);
        $this->assertSame("--idle --quiet --all\n", $this->readStubLog());
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
        $mdstat = $this->writeMdstat("md0 : active raid1 sda1[0] sdb1[1] 1047552 blocks [2/2] [UU]\n");
        file_put_contents($this->fixtureRoot.'/min-days', "365\n");
        $lastStarted = time() - 10;
        $this->pmssEnsureDir($this->fixtureRoot.'/state', 0700);
        file_put_contents($this->fixtureRoot.'/state/mdadm-checkarray-last', (string) $lastStarted."\n");

        $result = $this->runCommand($mdstat, $this->writeCheckarrayStub());

        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsString('skipping: last check '.date('c', $lastStarted).' is within the 365-day host minimum', $result['output']);
        $this->assertSame('', $this->readStubLog());
    }

    public function testMainRecordsFirstCheckWithHostMinimum(): void
    {
        $mdstat = $this->writeMdstat("md0 : active raid1 sda1[0] sdb1[1] 1047552 blocks [2/2] [UU]\n");
        file_put_contents($this->fixtureRoot.'/min-days', "365\n");
        $before = time();

        $result = $this->runCommand($mdstat, $this->writeCheckarrayStub());

        $this->assertSame(0, $result['rc']);
        $this->assertSame("--idle --quiet md0\n", $this->readStubLog());
        $lastStarted = (int) trim((string) file_get_contents($this->fixtureRoot.'/state/mdadm-checkarray-last'));
        $this->assertTrue($lastStarted >= $before && $lastStarted <= time());
    }

    public function testMalformedHostMinimumDoesNotSkip(): void
    {
        $mdstat = $this->writeMdstat("md0 : active raid1 sda1[0] sdb1[1] 1047552 blocks [2/2] [UU]\n");
        file_put_contents($this->fixtureRoot.'/min-days', "365 days\n");
        $this->pmssEnsureDir($this->fixtureRoot.'/state', 0700);
        file_put_contents($this->fixtureRoot.'/state/mdadm-checkarray-last', (string) time()."\n");

        $result = $this->runCommand($mdstat, $this->writeCheckarrayStub());

        $this->assertSame(0, $result['rc']);
        $this->assertSame("--idle --quiet md0\n", $this->readStubLog());
    }

    public function testFailedCheckDoesNotAdvanceState(): void
    {
        $mdstat = $this->writeMdstat("md0 : active raid1 sda1[0] sdb1[1] 1047552 blocks [2/2] [UU]\n");

        $result = $this->runCommand($mdstat, $this->writeCheckarrayStub(7));

        $this->assertSame(7, $result['rc']);
        $this->assertFalse(is_file($this->fixtureRoot.'/state/mdadm-checkarray-last'));
    }

    public function testStateWriteFailureKeepsSuccessfulExitCode(): void
    {
        $mdstat = $this->writeMdstat("md0 : active raid1 sda1[0] sdb1[1] 1047552 blocks [2/2] [UU]\n");
        $this->pmssEnsureDir($this->fixtureRoot.'/state/mdadm-checkarray-last', 0700);

        $result = $this->runCommand($mdstat, $this->writeCheckarrayStub());

        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsString('unable to write last-check state', $result['output']);
        $this->assertSame("--idle --quiet md0\n", $this->readStubLog());
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
        $cron = $this->pmssReadRepoFile('etc/seedbox/config/root.cron');

        $this->assertStringContainsString('/scripts/cron/mdadmCheckarray.php', $cron);
        $this->assertStringContainsString('[ $(date +\%d) -le 7 ]', $cron);
        $this->assertStringContainsString('$(($(date +\%-m) \% 3)) -eq $((H \% 3))', $cron);
        $this->assertStringNotContainsString('/usr/share/mdadm/checkarray'.' --cron --all', $cron);
    }

    private function writeMdstat(string $contents): string
    {
        return $this->pmssWriteRelativeFile($this->fixtureRoot, 'mdstat', $contents, 0700);
    }

    private function writeSysfsState(string $array, string $degraded): void
    {
        $dir = $this->fixtureRoot.'/sys/block/'.$array.'/md';
        $this->pmssEnsureDir($dir, 0700);
        file_put_contents($dir.'/degraded', $degraded."\n");
    }

    private function writeCheckarrayStub(int $exitCode = 0): string
    {
        $path = $this->fixtureRoot.'/checkarray';
        $body = "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >> \"\$PMSS_MDADM_CHECKARRAY_STUB_LOG\"\nexit ".$exitCode."\n";
        file_put_contents($path, $body);
        chmod($path, 0755);
        return $path;
    }

    private function readStubLog(): string
    {
        $path = $this->fixtureRoot.'/checkarray.log';
        return is_file($path) ? (string) file_get_contents($path) : '';
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
