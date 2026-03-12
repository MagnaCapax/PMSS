<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/show.php';

class ShowResourcesFormatTest extends TestCase
{
    public function testHelpIncludesCoreOptions(): void
    {
        $script = dirname(__DIR__, 3).'/showResources.php';
        $out = (string) shell_exec('php '.escapeshellarg($script).' --help 2>&1');

        $this->assertTrue(strpos($out, '--json') !== false);
        $this->assertTrue(strpos($out, '--show-missing') !== false);
        $this->assertTrue(strpos($out, '--user') !== false);
        $this->assertTrue(strpos($out, '--help') !== false);
    }

    public function testFormatBytesTiB(): void
    {
        $twoTiB = 2 * 1024 * 1024 * 1024 * 1024;
        $this->assertTrue(strpos(\pmssResourceFormatBytes($twoTiB), 'TiB') !== false);
    }

    public function testUserFilteredOutputFormatsCpuRamAndIops(): void
    {
        $runtimeDir = sys_get_temp_dir().'/pmss-show-runtime-'.bin2hex(random_bytes(4));
        $statsDir = $runtimeDir.'/resourceStats';
        @mkdir($statsDir, 0755, true);
        @file_put_contents($statsDir.'/alice', serialize([
            'io_read' => ['raw' => ['month' => 10, 'week' => 10, 'day' => 10, 'hour' => 10]],
            'io_write' => ['raw' => ['month' => 20, 'week' => 20, 'day' => 20, 'hour' => 20]],
            'io_read_ops' => ['raw' => ['month' => 30, 'week' => 30, 'day' => 30, 'hour' => 3600]],
            'io_write_ops' => ['raw' => ['month' => 40, 'week' => 40, 'day' => 40, 'hour' => 3600]],
            'cpu' => ['raw' => ['month' => 3600 * 1000000000, 'week' => 1, 'day' => 1, 'hour' => 1]],
            'memory' => ['current' => 1024 * 1024 * 1024, 'raw' => ['month' => 512 * 1024 * 1024]],
            'ram_hours' => ['raw' => ['month' => 2.5, 'week' => 2.5, 'day' => 2.5, 'hour' => 2.5]],
            'tasks' => ['current' => 3],
        ]));

        $script = dirname(__DIR__, 3).'/showResources.php';
        $output = [];
        $rc = 0;
        exec(
            'PMSS_RUNTIME_DIR='.escapeshellarg($runtimeDir).' php '.escapeshellarg($script).' --user=alice 2>&1',
            $output,
            $rc
        );

        $this->cleanup($runtimeDir);
        $textOutput = implode("\n", $output);
        $this->assertEquals(0, $rc);
        $this->assertTrue(strpos($textOutput, '1.0 hrs') !== false);
        $this->assertTrue(strpos($textOutput, '2.50 GB-hrs') !== false);
        $this->assertTrue(strpos($textOutput, '2.00') !== false);
    }

    private function cleanup(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
