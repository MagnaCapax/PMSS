<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/userTransfer.php';

/** Keep completion tests independent of host users and the real operator store. */
class UserTransferCompletionStoreStub extends \UserConfigStore
{
    public $payload;
    public $writes = 0;
    public $failWrite = false;

    public function __construct(?array $payload = []) { $this->payload = $payload; }
    public function get(string $username): ?array { return $this->payload; }
    public function set(string $username, array $payload): bool
    {
        ++$this->writes;
        if ($this->failWrite) return false;
        $this->payload = $payload;
        return true;
    }
    public function persist(string $username, array $payload): bool
    {
        throw new \RuntimeException('Completion must not publish a home cache');
    }
}

/** Repeat-transfer guards are independent of rsync and existing safety gates. */
class UserTransferRepeatSafetyTest extends TestCase
{
    private function config(array $overrides = []): array
    {
        return $overrides + ['localUser' => 'fixtur01', 'remoteUser' => 'fixtur02', 'hostname' => 'source.example'];
    }

    public function testForceIsExplicitAndDoesNotAlterDefaultConfigShape(): void
    {
        $argv = ['transfer', 'fixtur01', 'source.example'];
        $this->assertFalse(array_key_exists('force', \pmssUserTransferParseCli($argv)));
        $this->assertTrue(\pmssUserTransferParseCli(array_merge($argv, ['--force']))['force']);
        foreach (['--force=0', '--force=false', '--force=', '--force=true'] as $flag) {
            $this->assertThrowsRuntime(static function () use ($argv, $flag): void {
                \pmssUserTransferParseCli(array_merge($argv, [$flag]));
            }, 'does not accept a value');
        }
    }

    public function testCompletedSourceIsRefusedAcrossHostnameCase(): void
    {
        $store = new UserTransferCompletionStoreStub(['userTransferCompleted' => ['source.example/fixtur02' => '2026-10-20T00:00:00+00:00']]);
        foreach (['source.example', 'SOURCE.EXAMPLE'] as $hostname) {
            $cfg = $this->config(['hostname' => $hostname]);
            $this->assertThrowsRuntime(static function () use ($cfg, $store): void {
                \pmssUserTransferAssertNotRepeated($cfg, $store);
            }, 'already completed');
        }
        $this->assertSame(0, $store->writes);
    }

    public function testFirstTransferOtherSourcesForceAndRecoveryRemainAvailable(): void
    {
        foreach ([[], ['hostname' => 'another.example'], ['remoteUser' => 'fixtur03'], ['force' => true], ['into' => 'old-copy']] as $overrides) {
            $payload = $overrides === [] ? [] : ['userTransferCompleted' => ['source.example/fixtur02' => 'completed']];
            $store = new UserTransferCompletionStoreStub($payload);
            \pmssUserTransferAssertNotRepeated($this->config($overrides), $store);
            $this->assertSame(0, $store->writes);
        }
    }

    public function testMissingOrMalformedHistoryFailsClosedEvenWithForce(): void
    {
        foreach ([null, ['userTransferCompleted' => null], ['userTransferCompleted' => false], ['userTransferCompleted' => 'bad']] as $payload) {
            $store = new UserTransferCompletionStoreStub($payload);
            foreach ([[], ['force' => true]] as $overrides) {
                $cfg = $this->config($overrides);
                $this->assertThrowsRuntime(static function () use ($cfg, $store): void {
                    \pmssUserTransferAssertNotRepeated($cfg, $store);
                }, 'Cannot read transfer completion history');
            }
            \pmssUserTransferAssertNotRepeated($this->config(['into' => 'old-copy']), $store);
        }
    }

    public function testCompletionPreservesMetadataAndEarlierSourcesWithoutWritingHomeCache(): void
    {
        $store = new UserTransferCompletionStoreStub(['quota' => 10, 'movedUntil' => '2026-12-01',
            'userTransferCompleted' => ['earlier.example/fixtur03' => 'earlier']]);
        \pmssUserTransferRecordCompletion($this->config(), $store);
        $this->assertSame(1, $store->writes);
        $this->assertSame(10, $store->payload['quota']);
        $this->assertSame('2026-12-01', $store->payload['movedUntil']);
        $this->assertSame('earlier', $store->payload['userTransferCompleted']['earlier.example/fixtur03']);
        $this->assertTrue(strtotime($store->payload['userTransferCompleted']['source.example/fixtur02']) !== false);
        $this->assertThrowsRuntime(function () use ($store): void {
            \pmssUserTransferAssertNotRepeated($this->config(), $store);
        }, 'already completed');
    }

    public function testCompletionWriteFailureIsFatalAndRecoveryDoesNotRecord(): void
    {
        $store = new UserTransferCompletionStoreStub();
        $store->failWrite = true;
        $this->assertThrowsRuntime(function () use ($store): void {
            \pmssUserTransferRecordCompletion($this->config(), $store);
        }, 'Cannot save transfer completion history');
        $store->writes = 0;
        \pmssUserTransferRecordCompletion($this->config(['into' => 'old-copy']), $store);
        $this->assertSame(0, $store->writes);
    }

    public function testTimestampParserRetainsFractionalPrecisionAndAcceptsProbeBanners(): void
    {
        foreach (['0' => [0, 0], '123.1' => [123, 1000000000], '123.0000000010' => [123, 10],
            '123.9999999990' => [123, 9999999990], '124.0000000000' => [124, 0]] as $raw => $expected) {
            $this->assertSame($expected, \pmssUserTransferNewestFileTime("SSH banner\r\nPMSS_TRANSFER_NEWEST=".$raw."\r\n"));
        }
        $this->assertTrue(\pmssUserTransferNewestFileTime('PMSS_TRANSFER_NEWEST=123.0000000020')
            > \pmssUserTransferNewestFileTime('PMSS_TRANSFER_NEWEST=123.0000000010'));
    }

    public function testTimestampParserRefusesMissingDuplicateMalformedAndOverflowValues(): void
    {
        foreach (['', '123', 'PMSS_TRANSFER_NEWEST=-1', 'PMSS_TRANSFER_NEWEST=NaN', 'PMSS_TRANSFER_NEWEST=1.12345678901',
            'PMSS_TRANSFER_NEWEST='.PHP_INT_MAX.'0', "PMSS_TRANSFER_NEWEST=1\nPMSS_TRANSFER_NEWEST=2"] as $raw) {
            $this->assertThrowsRuntime(static function () use ($raw): void {
                \pmssUserTransferNewestFileTime($raw);
            }, 'Cannot establish newest file timestamp');
        }
    }

    public function testTimestampScanHandlesEmptyTreesNestedFilesAndSymlinks(): void
    {
        $home = $this->pmssMakeTempDir('pmss-transfer-newest-');
        $outside = $this->pmssMakeTempDir('pmss-transfer-newest-outside-');
        $scan = function () use ($home): array {
            $result = $this->pmssExecArgvCommand(['bash', '-c', \pmssUserTransferBuildNewestFileShell($home)]);
            $this->assertSame(0, $result['rc'], $result['output']);
            return \pmssUserTransferNewestFileTime($result['output']);
        };
        $this->assertSame([0, 0], $scan());
        $this->pmssWriteFile($home.'/nested/file with space', 'old');
        $this->pmssWriteFile($home."/file\nwith newline", 'new');
        $this->pmssWriteFile($outside.'/foreign', 'outside');
        touch($home.'/nested/file with space', 1700000000);
        touch($home."/file\nwith newline", 1700000001);
        touch($outside.'/foreign', 1700000002);
        symlink($outside.'/foreign', $home.'/linked-file');
        symlink($outside, $home.'/linked-directory');
        symlink($home.'/absent', $home.'/dangling');
        $this->assertSame([1700000001, 0], $scan());
        $failed = $this->pmssExecArgvCommand(['bash', '-c', \pmssUserTransferBuildNewestFileShell($home.'/absent')]);
        $this->assertTrue($failed['rc'] !== 0);
        $this->assertStringNotContainsString('PMSS_TRANSFER_NEWEST=', $failed['output']);
    }

    public function testTimestampGuardRefusesNewerTargetsAndFailedOrUnreadableScans(): void
    {
        $scratch = $this->pmssMakeTempDir('pmss-transfer-preflight-');
        $paths = \pmssUserTransferScratchPaths($scratch);
        $cfg = $this->config(['namespaceRsync' => true, 'receiver' => ['uid' => 1001, 'gid' => 1001, 'home' => $scratch]]);
        $previous = $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] ?? null;
        try {
            foreach ([[0, '10', 0, '9', null], [0, '10', 0, '10', null], [0, '10.0000000010', 0, '10.0000000020', 'Target home'],
                [1, '10', 0, '9', 'Cannot scan source'], [0, '10', 1, '9', 'Cannot scan target'],
                [0, 'bad', 0, '9', 'Cannot establish'], [0, '10', 0, 'bad', 'Cannot establish']] as $case) {
                $commands = [];
                $runner = static function ($label, $command) use (&$commands, $case): int {
                    $offset = count($commands) * 2;
                    $commands[] = $command;
                    $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] = ['stdout' => 'PMSS_TRANSFER_NEWEST='.$case[$offset + 1]];
                    return $case[$offset];
                };
                $check = static function () use ($cfg, $scratch, $paths, $runner): void {
                    \pmssUserTransferAssertSourceNotOlder($cfg, $scratch, $paths, $runner);
                };
                if ($case[4] === null) $check();
                else $this->assertThrowsRuntime($check, $case[4]);
                $this->assertTrue(count($commands) <= 2);
                if (count($commands) === 2) $this->assertStringContainsString('env -u PMSS_USER_TRANSFER_PASSWORD setpriv', $commands[1]);
            }
        } finally {
            if ($previous === null) unset($GLOBALS['PMSS_LAST_COMMAND_OUTPUT']);
            else $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] = $previous;
        }
    }

    public function testRecoveryAndForceSkipOnlyTheNewTimestampGuard(): void
    {
        foreach ([['into' => 'old-copy'], ['force' => true]] as $overrides) {
            \pmssUserTransferAssertSourceNotOlder($this->config($overrides), '/unused', [], static function (): int {
                throw new \RuntimeException('No scan should run');
            });
        }
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/lib/userTransfer.php',
            ['pmssUserTransferAssertSafeLocalHome', 'pmssUserTransferAssertNotRepeated', 'pmssUserTransferResolvePassword',
                'pmssUserTransferAssertSourceNotOlder', "pmssUserTransferRunPasses('Pulling home data', \$scratchPaths['expect'], \$scratchPaths['mainScript'], \$cfg['mainPasses']",
                'if ($lastMainRc !== 0 || $lastFinalRc !== 0)', 'pmssUserTransferRecordCompletion', "logMessage('[OK] User transfer complete')"]);
    }
}
