<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/filesystem.php';

class HomeInodeDensityTest extends TestCase
{
    private function runHomeInodeDensityCheck(string $home, ?string $binDir = null): array
    {
        $messages = [];
        $callback = function () use (&$messages, $home): void {
            $messages = $this->pmssArrayLoggerMessages(function (callable $logger) use ($home): void {
                \pmssHomeInodeDensityCheck($logger, $home, 262144);
            });
        };

        if ($binDir === null) {
            $callback();
        } else {
            $this->pmssWithPathPrefix($binDir, $callback);
        }
        return $messages;
    }

    private function runHomeInodeDensityCheckWithStat(array $statOutput, string $prefix): array
    {
        $home = $this->pmssMakeTempDir($prefix.'home-');
        $binDir = $this->pmssMakeLineOutputStub('stat', $statOutput, $prefix.'bin-');
        return $this->runHomeInodeDensityCheck($home, $binDir);
    }

    private function runUpdateStep2PreflightChecks(array $homePaths, array $lockPaths, array $cachePaths, float $minBytes = 1.0): array
    {
        [$result, $messages] = $this->pmssArrayLoggerCapture(function (callable $logger) use ($homePaths, $lockPaths, $cachePaths, $minBytes): bool {
            return \pmssUpdateStep2PreflightChecks($logger, $homePaths, $lockPaths, $cachePaths, '', $minBytes);
        });

        return [$result, $messages];
    }

    public function testParsesStatLineAndComputesBytesPerInode(): void
    {
        $stats = \pmssFilesystemStatLineParse('4096 1024 128');

        $this->assertEquals(['block_size' => 4096, 'blocks' => 1024, 'inodes' => 128], $stats);
        $this->assertEquals(32768.0, \pmssFilesystemBytesPerInode($stats));
    }

    public function testRejectsInvalidStatLines(): void
    {
        foreach (['', '4096 1024', '4096 1024 0', '4096 blocks 128', '-1 1024 128'] as $line) {
            $this->assertSame(null, \pmssFilesystemStatLineParse($line), 'line should be rejected: '.$line);
        }
    }

    public function testPreservesPaddedCountersAndIntegerBoundary(): void
    {
        foreach (['1', '0001', '4096', (string) PHP_INT_MAX, '000'.PHP_INT_MAX] as $value) {
            $expected = array_fill_keys(['block_size', 'blocks', 'inodes'], (int) $value);
            $this->assertSame($expected, \pmssFilesystemStatLineParse("\t$value  $value\t$value extra\r\n"));
        }
    }

    public function testRejectsOverflowInEveryStatCounter(): void
    {
        $tooLarge = substr((string) PHP_INT_MAX, 0, -1).'8';
        foreach ([$tooLarge, '000'.$tooLarge, PHP_INT_MAX.'0', str_repeat('9', 400)] as $value) {
            foreach ([0, 1, 2] as $index) {
                $parts = ['4096', '1024', '128'];
                $parts[$index] = $value;
                $this->assertSame(null, \pmssFilesystemStatLineParse(implode(' ', $parts)));
            }
        }
    }

    public function testRejectsInvalidAndNonFiniteDensityOperands(): void
    {
        $valid = ['block_size' => 4096, 'blocks' => 1024, 'inodes' => 128];
        foreach (array_keys($valid) as $key) {
            $missing = $valid;
            unset($missing[$key]);
            $this->assertSame(null, \pmssFilesystemBytesPerInode($missing));
            foreach ([null, '', 'bad', [], true, 0, -1, NAN, INF, -INF, '1e309', '1e-999'] as $value) {
                $this->assertSame(null, \pmssFilesystemBytesPerInode([$key => $value] + $valid));
            }
        }
    }

    public function testDensityArithmeticRejectsOverflowAndUnderflow(): void
    {
        foreach ([[PHP_FLOAT_MAX, 2, 1], [1, 1, 1e-309], [1e-300, 1e-300, 1], [1e-300, 1, 1e300]] as $values) {
            $stats = array_combine(['block_size', 'blocks', 'inodes'], $values);
            $this->assertSame(null, \pmssFilesystemBytesPerInode($stats));
        }
    }

    public function testPreservesFiniteDensityCalculations(): void
    {
        foreach ([1, '1', '001', 1.5, '1e3', 1e-100, (float) PHP_INT_MAX / 2] as $value) {
            $stats = ['block_size' => $value, 'blocks' => 1, 'inodes' => 1];
            $this->assertSame((float) $value, \pmssFilesystemBytesPerInode($stats));
        }
    }

    public function testUnrepresentableStatOutputRetainsParseWarning(): void
    {
        // A wrapped integer must never turn an extreme density into an OK result.
        $overflow = PHP_INT_MAX.'0';
        foreach (["$overflow 1 1", "1 $overflow 1", "1 1 $overflow", PHP_INT_MAX.' 2 1', PHP_INT_MAX.' '.PHP_INT_MAX.' 1'] as $line) {
            $messages = $this->runHomeInodeDensityCheckWithStat([$line], 'pmss-inode-overflow-');
            $this->assertSame(1, count($messages));
            $this->pmssAssertMessagesContain($messages, '[WARN] Unable to parse home inode density from stat output');
        }
    }

    public function testLogsOkWhenInodeDensityIsBelowThreshold(): void
    {
        $messages = $this->runHomeInodeDensityCheckWithStat(['4096 1024 128'], 'pmss-inode-ok-');

        $this->pmssAssertMessagesContain($messages, '[OK] Home inode density');
    }

    public function testLogsWarnWhenInodeDensityExceedsThreshold(): void
    {
        $messages = $this->runHomeInodeDensityCheckWithStat(['4096 1048576 1024'], 'pmss-inode-warn-');

        $this->pmssAssertMessagesContain($messages, '[WARN] Home inode density');
        $this->pmssAssertMessagesContain($messages, 'media-stack workloads may exhaust inodes');
    }

    public function testWarnsWhenStatFails(): void
    {
        $home = $this->pmssMakeTempDir('pmss-inode-fail-home-');
        $binDir = $this->pmssMakeExecutableStub('stat', "#!/bin/sh\nexit 9\n", 'pmss-inode-fail-bin-');
        $messages = $this->runHomeInodeDensityCheck($home, $binDir);

        $this->pmssAssertMessagesContain($messages, 'stat rc=9');
    }

    public function testSkipsWhenHomePathIsMissing(): void
    {
        $messages = $this->runHomeInodeDensityCheck('/tmp/pmss-missing-home-path');

        $this->pmssAssertMessagesContain($messages, 'path missing');
    }

    public function testUpdateStep2PreflightKeepsFatalAndWarningOnlyContracts(): void
    {
        $home = $this->pmssMakeTempDir('pmss-preflight-disk-home-');
        [$result, $messages] = $this->runUpdateStep2PreflightChecks([$home], [], [], 1.0E20);
        $this->assertFalse($result, 'Fatal disk-space failures should abort the caller');
        $this->assertStringContainsAllStrings(['Insufficient free space on', 'Preflight checks failed (fatal)'], implode("\n", $messages));

        $missingBase = sys_get_temp_dir().'/pmss-preflight-missing-'.uniqid('', true);
        [$result, $messages] = $this->runUpdateStep2PreflightChecks([], [$missingBase.'/dpkg.lock'], [$missingBase.'/apt-cache']);
        $this->assertTrue($result, 'Warning-only preflight failures should not abort update-step2');
        $this->assertStringContainsAllStrings(['Unable to open dpkg lock file', 'APT cache path missing or not writable'], implode("\n", $messages));
    }

    /** Inject both disk readings so the phase-2 cases never probe host disks. */
    private function runDiskPreflight(array $paths, int $uid, float $userAvailable, ?float $rootUsable): array
    {
        $readings = [];
        $measure = static function (string $path, string $kind) use ($userAvailable, $rootUsable, &$readings) {
            $readings[] = $path.':'.$kind;
            return $kind === 'root' ? $rootUsable : $userAvailable;
        };
        [$result, $messages] = $this->pmssArrayLoggerCapture(static function (callable $logger) use ($paths, $measure, $uid): bool {
            return \pmssUpdateStep2PreflightChecks($logger, $paths, [], [], '', 3.0 * 1073741824, $measure, $uid);
        });
        return [$result, $messages, $readings];
    }

    public function testRootHomePassesOnReservedBlocks(): void
    {
        [$result, $messages, $readings] = $this->runDiskPreflight(['/home'], 0, 0.0, 22.0 * 1073741824);
        $this->assertTrue($result);
        $this->assertSame(['/home:user', '/home:root'], $readings);
        $this->pmssAssertMessagesContain($messages, 'user-available 0.00 GiB, root-usable 22.00 GiB');
        $this->pmssAssertMessagesContain($messages, '[WARN] /home user-available space is below 3 GiB; update is using root-reserved blocks');
    }

    public function testRootHomeFailsWhenRootUsableSpaceIsLow(): void
    {
        [$result, $messages] = $this->runDiskPreflight(['/home'], 0, 0.0, 2.0 * 1073741824);
        $this->assertFalse($result);
        $this->pmssAssertMessagesContain($messages, '2 GiB available, 3 GiB required');
    }

    public function testMalformedRootReadFallsBackToUserAllowance(): void
    {
        [$result, $messages] = $this->runDiskPreflight(['/home'], 0, 0.0, \pmssRootUsableDiskBytes('bad'));
        $this->assertFalse($result);
        $this->pmssAssertMessagesContain($messages, '[WARN] Unable to determine root-usable free space for /home');
        $this->pmssAssertMessagesContain($messages, '0 GiB available, 3 GiB required');
    }

    public function testNonRootHomeUsesUserAllowance(): void
    {
        [$result, , $readings] = $this->runDiskPreflight(['/home'], 1000, 0.0, 22.0 * 1073741824);
        $this->assertFalse($result);
        $this->assertSame(['/home:user'], $readings);
    }

    public function testRootFilesystemUsesUserAllowance(): void
    {
        [$result, , $readings] = $this->runDiskPreflight(['/'], 0, 0.0, 22.0 * 1073741824);
        $this->assertFalse($result);
        $this->assertSame(['/:user'], $readings);
    }
}
