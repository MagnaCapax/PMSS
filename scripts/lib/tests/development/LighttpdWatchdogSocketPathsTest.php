<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/lighttpd/watchdog.php';

class LighttpdWatchdogSocketPathsTest extends TestCase
{
    /**
     * @var string
     */
    private $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/pmss-lighttpd-watchdog-'.bin2hex(random_bytes(4));
        @mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempDir);
    }

    public function testReadsConfiguredMaxProcs(): void
    {
        $configPath = $this->writeConfig('"max-procs" => 6');

        $this->assertEquals(6, \pmssLighttpdWatchdogConfigMaxProcs($configPath));
    }

    public function testReturnsNullWhenMaxProcsMissing(): void
    {
        $configPath = $this->writeConfig('server.port = 12345');

        $this->assertEquals(null, \pmssLighttpdWatchdogConfigMaxProcs($configPath));
    }

    public function testReturnsNullWhenMaxProcsInvalid(): void
    {
        $configPath = $this->writeConfig('"max-procs" => 0');

        $this->assertEquals(null, \pmssLighttpdWatchdogConfigMaxProcs($configPath));
    }

    public function testBuildsSingleSocketPathWhenOnlyOneWorkerExpected(): void
    {
        $configPath = $this->writeConfig('"max-procs" => 1');

        $this->assertEquals(
            [$this->tempDir.'/.lighttpd/php.socket'],
            \pmssLighttpdWatchdogSocketPaths($this->tempDir, $configPath)
        );
    }

    public function testBuildsEverySocketPathWhenMultipleWorkersExpected(): void
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

    private function removeTree(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        foreach ((array) glob($path.'/*') as $childPath) {
            $this->removeTree($childPath);
        }

        @rmdir($path);
    }
}
