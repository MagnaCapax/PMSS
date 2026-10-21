<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/lighttpd/userConfigApply.php';

class LighttpdWatchdogSocketPathsTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-lighttpd-watchdog-']; }

    public function testFifoConfigFallsBackWithoutBlocking(): void
    {
        $configPath = $this->tempDir.'/.lighttpd.conf';
        $this->assertTrue(posix_mkfifo($configPath, 0600));
        $started = microtime(true);
        $this->assertEquals([$this->tempDir.'/.lighttpd/php.socket-0'],
            \pmssLighttpdWatchdogSocketPaths($this->tempDir, $configPath));
        $this->assertTrue(microtime(true) - $started < 2);
        $source = $this->pmssReadRepoFile('scripts/startLighttpd');
        $this->assertTrue(strpos($source, 'pmssReadRegularFileContentsVerified($configPath, null, 1048576)') !== false);
        $this->assertSame(null, \pmssReadRegularFileContentsVerified($configPath, null, 1048576));
    }

    public function testStartLighttpdFifoDeflateProbeKeepsCompressionOff(): void
    {
        $root = $this->pmssMakeTempDir('pmss-lighttpd-start-fifo-');
        $user = 'alice';
        $home = $root.'/homes/'.$user;
        mkdir($home, 0755, true);
        $this->assertTrue(posix_mkfifo($home.'/.lighttpd.conf', 0600));
        $this->pmssWriteExecutableFiles($root, [
            'pgrep' => "#!/bin/sh\nexit 1\n",
            'id' => "#!/bin/sh\necho 2002\n",
            'systemctl' => "#!/bin/sh\nexit 0\n",
            'systemd-run' => "#!/bin/sh\nexit 1\n",
            'su' => "#!/bin/sh\nfor arg do if [ \"\$prev\" = '-c' ]; then /bin/sh -c \"\$arg\"; exit \$?; fi; prev=\"\$arg\"; done\nexit 9\n",
        ]);
        $env = $this->pmssPathPrefixedEnvironment($root, [
            'PMSS_HOME_DIR' => $root.'/homes',
            'PMSS_RUNTIME_LOCK_DIR' => $root.'/locks',
            'PMSS_RUNTIME_DIR' => $root.'/runtime',
            'PMSS_TEST_MODE' => '1',
            'PMSS_WATCHDOG_TIMEOUT_TEST_SECONDS' => '1',
        ]);
        $command = 'timeout --kill-after=2s 8s '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($this->pmssRepoPath('scripts/startLighttpd')).' '.escapeshellarg($user);
        $result = $this->pmssExecShellCommand($command, $env);
        $this->assertSame(1, $result['rc']);
        $this->assertTrue(is_dir($home.'/.lighttpd/upload'));
        $this->assertFalse(is_dir($home.'/.lighttpd/compress'));
    }

    public function testBuildsOnlyStartupSocketPathsWhenConfigHasDemandSpawnedWorkers(): void
    {
        $configPath = $this->writeConfig("\"max-procs\" => 6\n\"min-procs\" => 1");

        $this->assertEquals(
            [$this->tempDir.'/.lighttpd/php.socket-0'],
            \pmssLighttpdWatchdogSocketPaths($this->tempDir, $configPath)
        );
    }

    public function testFallsBackToLegacySocketProbeWhenMaxProcsMissing(): void
    {
        $configPath = $this->writeConfig('server.port = 12345');

        $this->assertEquals(
            [$this->tempDir.'/.lighttpd/php.socket-0'],
            \pmssLighttpdWatchdogSocketPaths($this->tempDir, $configPath)
        );
    }

    public function testFallsBackToLegacySocketProbeWhenMaxProcsInvalid(): void
    {
        $configPath = $this->writeConfig('"max-procs" => 0');

        $this->assertEquals(
            [$this->tempDir.'/.lighttpd/php.socket-0'],
            \pmssLighttpdWatchdogSocketPaths($this->tempDir, $configPath)
        );
    }

    public function testBuildsSingleSocketPathWhenOnlyOneWorkerExpected(): void
    {
        $configPath = $this->writeConfig("\"max-procs\" => 1\n\"min-procs\" => 1");

        $this->assertEquals(
            [$this->tempDir.'/.lighttpd/php.socket-0'],
            \pmssLighttpdWatchdogSocketPaths($this->tempDir, $configPath)
        );
    }

    public function testBuildsSingleSocketPathWhenMinProcsMissing(): void
    {
        $configPath = $this->writeConfig('"max-procs" => 1');

        $this->assertEquals(
            [$this->tempDir.'/.lighttpd/php.socket-0'],
            \pmssLighttpdWatchdogSocketPaths($this->tempDir, $configPath)
        );
    }

    public function testBuildsEveryStartupSocketPathWhenMinProcsMatchesMultipleWorkers(): void
    {
        $configPath = $this->writeConfig("\"max-procs\" => 6\n\"min-procs\" => 3");

        $this->assertEquals(
            [
                $this->tempDir.'/.lighttpd/php.socket-0',
                $this->tempDir.'/.lighttpd/php.socket-1',
                $this->tempDir.'/.lighttpd/php.socket-2',
            ],
            \pmssLighttpdWatchdogSocketPaths($this->tempDir, $configPath)
        );
    }

    public function testFallsBackToMaxProcsWhenMinProcsMissing(): void
    {
        $configPath = $this->writeConfig('"max-procs" => 3');

        $this->assertEquals(
            [
                $this->tempDir.'/.lighttpd/php.socket-0',
                $this->tempDir.'/.lighttpd/php.socket-1',
                $this->tempDir.'/.lighttpd/php.socket-2',
            ],
            \pmssLighttpdWatchdogSocketPaths($this->tempDir, $configPath)
        );
    }

    public function testClampsInvalidMinProcsToConfiguredMaxProcs(): void
    {
        $configPath = $this->writeConfig("\"max-procs\" => 3\n\"min-procs\" => 6");

        $this->assertEquals(
            [
                $this->tempDir.'/.lighttpd/php.socket-0',
                $this->tempDir.'/.lighttpd/php.socket-1',
                $this->tempDir.'/.lighttpd/php.socket-2',
            ],
            \pmssLighttpdWatchdogSocketPaths($this->tempDir, $configPath)
        );
    }

    public function testFallsBackToLegacySocketProbeWhenConfigMissing(): void
    {
        $configPath = $this->tempDir.'/missing.conf';

        $this->assertEquals(
            [$this->tempDir.'/.lighttpd/php.socket-0'],
            \pmssLighttpdWatchdogSocketPaths($this->tempDir, $configPath)
        );
    }

    private function writeConfig(string $body): string
    {
        $configPath = $this->tempDir.'/lighttpd.conf';
        file_put_contents($configPath, $body."\n");

        return $configPath;
    }
}
