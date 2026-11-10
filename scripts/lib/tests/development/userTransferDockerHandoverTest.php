<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/userTransfer.php';

/** Exercise transfer hand-over failures without SSH, Docker or account changes. */
class UserTransferDockerHandoverTest extends TestCase
{
    public function testCappedQuiesceOutputPreservesSshFailuresAndPrivateIds(): void
    {
        $root = $this->pmssMakeTempDir('pmss-transfer-docker-');
        $bin = $this->pmssMakeExecutableStub('ssh',
            "#!/bin/bash\nprintf '%s\\n' \"\$PMSS_TEST_DOCKER_IDS\"\nexit \"\$PMSS_TEST_SSH_RC\"\n",
            'pmss-transfer-ssh-');
        $idsPath = $root.'/ids';
        $script = $this->pmssWriteFile($root.'/quiesce.sh', \pmssUserTransferBuildDockerQuiesce(
            ['remoteUser' => 'fixtur02', 'hostname' => 'source.example'], $idsPath));
        foreach ([0, 1, 23, 255] as $rc) {
            $result = $this->pmssExecShellCommand('bash '.escapeshellarg($script), $this->pmssPathPrefixedEnvironment($bin, [
                'PMSS_TEST_DOCKER_IDS' => str_repeat('a', 64),
                'PMSS_TEST_SSH_RC' => (string) $rc,
            ]));
            $this->assertSame($rc === 0, $result['rc'] === 0, 'SSH rc='.$rc);
            $this->assertSame('', $result['output']);
            $this->assertSame(str_repeat('a', 64)."\n", file_get_contents($idsPath));
            $this->assertSame(0600, fileperms($idsPath) & 0777);
        }
        // A failed capture cannot silently authorize promotion either.
        $script = $this->pmssWriteFile($script, \pmssUserTransferBuildDockerQuiesce(
            ['remoteUser' => 'fixtur02', 'hostname' => 'source.example'], $root.'/absent/ids'));
        $result = $this->pmssExecShellCommand('bash '.escapeshellarg($script), $this->pmssPathPrefixedEnvironment($bin, [
            'PMSS_TEST_DOCKER_IDS' => '', 'PMSS_TEST_SSH_RC' => '0',
        ]));
        $this->assertTrue($result['rc'] !== 0);
    }

    public function testQuiesceFailureRestoresOnlyCapturedSourceSetBeforeAborting(): void
    {
        foreach (['', str_repeat('a', 64)] as $ids) {
            $result = $this->handover(['Quiescing source rootless Docker containers' => 1], $ids);
            $this->assertSame(1, $result['rc']);
            $expected = ['Quiescing source rootless Docker containers'];
            if ($ids !== '') $expected[] = 'Restarting source rootless Docker containers';
            $this->assertSame($expected, $result['steps']);
            $this->assertStringNotContainsString('stopped on source', implode("\n", $result['logs']));
        }
    }

    public function testFailedFinalCopyRestoresSourceBeforeTargetPostSetup(): void
    {
        foreach ([12, 255] as $rc) {
            $result = $this->handover(['Pulling volatile data (containers stopped)' => $rc]);
            $this->assertSame(1, $result['rc']);
            $this->assertSame(['Quiescing source rootless Docker containers',
                'Pulling volatile data (containers stopped)', 'Restarting source rootless Docker containers'], $result['steps']);
        }
    }

    public function testAcceptedCopyStartsTargetAfterPostSetupWithoutLoggingIds(): void
    {
        foreach ([0, 23, 24] as $rc) {
            $result = $this->handover(['Pulling volatile data (containers stopped)' => $rc]);
            $this->assertSame(0, $result['rc']);
            $this->assertSame(['Quiescing source rootless Docker containers',
                'Pulling volatile data (containers stopped)', 'post-setup',
                'Starting rootless Docker for fixtur01', 'Starting transferred containers'], $result['steps']);
            $this->assertStringNotContainsString(str_repeat('a', 64), implode("\n", $result['logs']));
        }
    }

    public function testTargetStartFailuresAbortWithoutResumingSourceContainers(): void
    {
        foreach (['Starting rootless Docker for fixtur01', 'Starting transferred containers'] as $step) {
            $result = $this->handover([$step => 1]);
            $this->assertSame(1, $result['rc']);
            $this->assertSame($step, end($result['steps']));
            $this->assertFalse(in_array('Restarting source rootless Docker containers', $result['steps'], true));
        }
    }

    public function testEmptySourceAndRecoveryNeverStartDocker(): void
    {
        $this->assertSame(['Quiescing source rootless Docker containers', 'post-setup'],
            $this->handover([], '')['steps']);
        $this->assertSame(['post-setup'], $this->handover([], str_repeat('a', 64), true)['steps']);
    }

    /** Load just the real hand-over block; all side effects are captured in memory. */
    private function handover(array $failures, string $ids = '', bool $recovery = false): array
    {
        static $loaded = false;
        if (!$loaded) {
            $source = file_get_contents($this->pmssRepoPath('scripts/lib/userTransfer.php'));
            $start = strpos($source, '            $dockerIds = [];');
            $end = strpos($source, '            if ($cfg[\'printPassword\'])', $start);
            $this->assertTrue($start !== false && $end !== false);
            $fixture = <<<'PHP'
namespace PMSS\Tests\TransferDockerFixture;
use RuntimeException;
function runStep($step, $command) {
    $GLOBALS['transferDocker']['steps'][] = $step;
    return $GLOBALS['transferDocker']['failures'][$step] ?? 0;
}
function logMessage($message) { $GLOBALS['transferDocker']['logs'][] = $message; }
function file_get_contents($path) { return $GLOBALS['transferDocker']['ids']; }
function pmssUserTransferWriteFile($path, $contents, $mode) {}
function pmssUserTransferRunPasses($step, ...$args) { return runStep($step, ''); }
function pmssUserTransferPostSetup(...$args) { runStep('post-setup', ''); }
function handover($cfg, $scratchPaths, $home, $recovery) {
PHP;
            eval($fixture.substr($source, $start, $end - $start)."\nreturn 0; }");
            $loaded = true;
        }
        // Unless the caller explicitly tests an empty set, one active container moves.
        if (func_num_args() === 1) $ids = str_repeat('a', 64);
        $GLOBALS['transferDocker'] = ['failures' => $failures, 'ids' => $ids, 'steps' => [], 'logs' => []];
        $cfg = ['localUser' => 'fixtur01', 'remoteUser' => 'fixtur02', 'hostname' => 'source.example'];
        try {
            $rc = \PMSS\Tests\TransferDockerFixture\handover($cfg,
                \pmssUserTransferScratchPaths('/unused'), '/home/fixtur01', $recovery);
        } catch (\RuntimeException $e) {
            $rc = $e->getCode();
        }
        $result = ['rc' => $rc] + $GLOBALS['transferDocker'];
        unset($GLOBALS['transferDocker']);
        return $result;
    }
}
