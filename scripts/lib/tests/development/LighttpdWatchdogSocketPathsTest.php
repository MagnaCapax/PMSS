<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/lighttpd/userConfigApply.php';

class LighttpdWatchdogSocketPathsTest extends TestCase
{
    /**
     * @var string
     */
    private $tempDir;

    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-lighttpd-watchdog-');
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
            [$this->tempDir.'/.lighttpd/php.socket'],
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
