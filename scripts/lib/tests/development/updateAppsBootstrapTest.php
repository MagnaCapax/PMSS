<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateAppsBootstrapTest extends TestCase
{
    /**
     * @var string
     */
    private $tempDir;

    private function readFile(string $path): string
    {
        $contents = @file_get_contents($path);
        $this->assertTrue(is_string($contents) && $contents !== '', 'Unable to read '.$path);
        return $contents;
    }

    public function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/pmss-update-app-bootstrap-'.uniqid('', true);
        $this->assertTrue(@mkdir($this->tempDir, 0700, true), 'Unable to create temp dir');
    }

    public function tearDown(): void
    {
        if (!is_string($this->tempDir) || $this->tempDir === '' || !is_dir($this->tempDir)) {
            return;
        }

        $paths = [$this->tempDir];
        while ($paths !== []) {
            $path = array_pop($paths);
            if (is_dir($path)) {
                $entries = scandir($path);
                if ($entries === false) {
                    continue;
                }

                $children = array_values(array_diff($entries, ['.', '..']));
                if ($children === []) {
                    @rmdir($path);
                    continue;
                }

                $paths[] = $path;
                foreach ($children as $child) {
                    $paths[] = $path.'/'.$child;
                }
                continue;
            }

            @unlink($path);
        }
    }

    private function appBootstrapOutput(string $installerFile): string
    {
        $sourceDir = dirname(__DIR__, 2).'/update/apps';
        $sandboxDir = $this->tempDir.'/apps';
        $this->assertTrue(@mkdir($sandboxDir, 0700, true), 'Unable to create sandbox app dir');

        foreach ([$installerFile, 'bootstrap.php'] as $file) {
            $sourcePath = $sourceDir.'/'.$file;
            if (!is_file($sourcePath)) {
                continue;
            }

            $targetPath = $sandboxDir.'/'.$file;
            $this->assertTrue(@copy($sourcePath, $targetPath), 'Unable to copy '.$file.' into sandbox');
        }

        $command = 'php -r '.escapeshellarg('include '.var_export($sandboxDir.'/'.$installerFile, true).';').' 2>&1';
        $output = @shell_exec($command);
        $this->assertTrue(is_string($output), 'Unable to execute sandboxed installer');

        return $output;
    }

    public function testRadarrWarnsAndReturnsWhenRuntimeHelperMissing(): void
    {
        $output = $this->appBootstrapOutput('radarr.php');

        $this->assertStringContainsString('Radarr updater: missing runtime helper', $output);
        $this->assertTrue(strpos($output, 'Fatal error') === false, 'Radarr bootstrap should soft-return when runtime is missing');
    }

    public function testSonarrWarnsAndReturnsWhenRuntimeHelperMissing(): void
    {
        $output = $this->appBootstrapOutput('sonarr.php');

        $this->assertStringContainsString('Sonarr updater: missing runtime helper', $output);
        $this->assertTrue(strpos($output, 'Fatal error') === false, 'Sonarr bootstrap should soft-return when runtime is missing');
    }

    public function testUpdateStep2SkipsHelperModulesInAppLoader(): void
    {
        $path = dirname(__DIR__, 4).'/scripts/util/update-step2.php';
        $contents = $this->readFile($path);

        $this->assertStringContainsString("'arr.php'", $contents);
        $this->assertStringContainsString("'pythonVenv.php'", $contents);
        $this->assertStringContainsString("'remoteBinary.php'", $contents);
    }

    public function testRadarrKeepsInlineRuntimeBootstrapPath(): void
    {
        $path = dirname(__DIR__, 2).'/update/apps/radarr.php';
        $contents = $this->readFile($path);

        $this->assertStringContainsString("dirname(__DIR__).'/runtime.php'", $contents);
        $this->assertStringContainsString('Radarr updater: missing runtime helper', $contents);
        $this->assertTrue(strpos($contents, "require_once __DIR__.'/bootstrap.php';") === false, 'Radarr should not require a separate bootstrap helper');
    }

    public function testSonarrKeepsInlineRuntimeBootstrapPath(): void
    {
        $path = dirname(__DIR__, 2).'/update/apps/sonarr.php';
        $contents = $this->readFile($path);

        $this->assertStringContainsString("dirname(__DIR__).'/runtime.php'", $contents);
        $this->assertStringContainsString('Sonarr updater: missing runtime helper', $contents);
        $this->assertTrue(strpos($contents, "require_once __DIR__.'/bootstrap.php';") === false, 'Sonarr should not require a separate bootstrap helper');
    }
}
