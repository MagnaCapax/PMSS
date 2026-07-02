<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/mdadmCheckarray.php';

class MdadmCheckarrayTest extends TestCase
{
    /** @var string */
    private $fixtureRoot;

    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('fixtureRoot', 'pmss-mdadm-checkarray-', 0700);
    }

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
        $this->writeSyncAction('md1', 'check');
        $stub = $this->writeCheckarrayStub();

        $result = $this->runCommand($mdstat, $stub);

        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsString('skipping md1 (degraded); requested sync_action=idle', $result['output']);
        $this->assertStringContainsString('checking non-degraded arrays: md0', $result['output']);
        $this->assertStringContainsString('--cron --idle --quiet md0', $this->readStubLog());
        $this->assertSame("idle\n", (string) file_get_contents($this->syncActionPath('md1')));
    }

    public function testMainFallsBackToAllOnTotalEnumerationFailure(): void
    {
        $stub = $this->writeCheckarrayStub();

        $result = $this->runCommand($this->fixtureRoot.'/missing-mdstat', $stub);

        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsString('preserving checkarray --all behavior', $result['output']);
        $this->assertStringContainsString('--cron --idle --quiet --all', $this->readStubLog());
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

    private function syncActionPath(string $array): string
    {
        return $this->fixtureRoot.'/sys/block/'.$array.'/md/sync_action';
    }

    private function writeSyncAction(string $array, string $value): void
    {
        $dir = dirname($this->syncActionPath($array));
        $this->pmssEnsureDir($dir, 0700);
        file_put_contents($this->syncActionPath($array), $value."\n");
    }

    private function writeCheckarrayStub(): string
    {
        $path = $this->fixtureRoot.'/checkarray';
        $body = "#!/usr/bin/env bash\nprintf '%s\\n' \"$*\" >> \"$PMSS_MDADM_CHECKARRAY_STUB_LOG\"\n";
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
            ]
        );
    }
}
