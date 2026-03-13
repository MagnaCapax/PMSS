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
        $runtimeDir = sys_get_temp_dir().'/pmss-show-runtime-'.bin2hex(random_bytes(4));
        $statsDir = $runtimeDir.'/resourceStats';
        @mkdir($statsDir, 0755, true);
        @file_put_contents($statsDir.'/alice', serialize([
            'io_read' => ['raw' => ['month' => 2 * 1024 * 1024 * 1024 * 1024, 'week' => 1, 'day' => 1, 'hour' => 1]],
            'io_write' => ['raw' => ['month' => 20, 'week' => 20, 'day' => 20, 'hour' => 20]],
            'io_read_ops' => ['raw' => ['month' => 30, 'week' => 30, 'day' => 30, 'hour' => 3600]],
            'io_write_ops' => ['raw' => ['month' => 40, 'week' => 40, 'day' => 40, 'hour' => 3600]],
            'cpu' => ['raw' => ['month' => 3600 * 1000000000, 'week' => 1, 'day' => 1, 'hour' => 1]],
            'memory' => ['current' => 1024 * 1024 * 1024, 'raw' => ['month' => 512 * 1024 * 1024]],
            'ram_hours' => ['raw' => ['month' => 2.5, 'week' => 2.5, 'day' => 2.5, 'hour' => 2.5]],
            'tasks' => ['current' => 3],
        ]));

        $script = dirname(__DIR__, 3).'/showResources.php';
        $out = (string) shell_exec(
            'PMSS_RUNTIME_DIR='.escapeshellarg($runtimeDir)
            .' php '.escapeshellarg($script).' --user=alice 2>&1'
        );

        $this->cleanup($runtimeDir);
        $this->assertTrue(strpos($out, '2.00 TiB') !== false);
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
        $this->assertTrue(strpos($textOutput, '1.00 GiB') !== false);
        $this->assertTrue(strpos($textOutput, '2.00') !== false);
    }

    public function testUserFilteredJsonOutputKeepsExpectedPayloadShape(): void
    {
        $runtimeDir = sys_get_temp_dir().'/pmss-show-runtime-'.bin2hex(random_bytes(4));
        $statsDir = $runtimeDir.'/resourceStats';
        @mkdir($statsDir, 0755, true);
        @file_put_contents($statsDir.'/alice', serialize([
            'io_read' => ['raw' => ['month' => 1.0, 'week' => 1.0, 'day' => 1.0, 'hour' => 1.0]],
            'io_write' => ['raw' => ['month' => 2.0, 'week' => 2.0, 'day' => 2.0, 'hour' => 2.0]],
            'io_read_ops' => ['raw' => ['month' => 3.0, 'week' => 3.0, 'day' => 3.0, 'hour' => 3.0]],
            'io_write_ops' => ['raw' => ['month' => 4.0, 'week' => 4.0, 'day' => 4.0, 'hour' => 4.0]],
            'cpu' => ['raw' => ['month' => 5.0, 'week' => 5.0, 'day' => 5.0, 'hour' => 5.0]],
            'memory' => ['current' => 6.0, 'raw' => ['month' => 7.0]],
            'ram_hours' => ['raw' => ['month' => 8.0, 'week' => 8.0, 'day' => 8.0, 'hour' => 8.0]],
            'tasks' => ['current' => 9.0],
            'ignored_field' => ['month' => 999.0],
        ]));

        $script = dirname(__DIR__, 3).'/showResources.php';
        $json = (string) shell_exec(
            'PMSS_RUNTIME_DIR='.escapeshellarg($runtimeDir)
            .' php '.escapeshellarg($script).' --json --user=alice 2>&1'
        );

        $this->cleanup($runtimeDir);
        $payload = json_decode($json, true);
        $this->assertTrue(is_array($payload));
        $this->assertEquals(6.0, $payload['users']['alice']['memory']['current']);
        $this->assertEquals(8.0, $payload['users']['alice']['ram_hours']['month']);
        $this->assertEquals(9.0, $payload['totals']['tasks']['current']);
        $this->assertEquals([], $payload['missing']);
        $this->assertTrue(!isset($payload['users']['alice']['ignored_field']));
    }

    public function testUserFilteredJsonOutputMarksMissingWithoutRows(): void
    {
        $runtimeDir = sys_get_temp_dir().'/pmss-show-runtime-'.bin2hex(random_bytes(4));
        @mkdir($runtimeDir.'/resourceStats', 0755, true);

        $script = dirname(__DIR__, 3).'/showResources.php';
        $json = (string) shell_exec(
            'PMSS_RUNTIME_DIR='.escapeshellarg($runtimeDir)
            .' php '.escapeshellarg($script).' --json --user=ghost 2>&1'
        );

        $this->cleanup($runtimeDir);
        $payload = json_decode($json, true);
        $this->assertTrue(is_array($payload));
        $this->assertEquals([], $payload['users']);
        $this->assertEquals(0.0, $payload['totals']['memory']['current']);
        $this->assertEquals(['ghost'], $payload['missing']);
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
