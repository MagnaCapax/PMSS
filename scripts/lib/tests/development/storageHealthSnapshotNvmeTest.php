<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/FilesystemCleanupTrait.php';
require_once dirname(__DIR__, 2).'/storageHealth.php';

final class StorageHealthSnapshotNvmeTest extends TestCase
{
    use FilesystemCleanupTrait;

    /** @var string|false */
    private $previousPath;

    /** @var array<int, string> */
    private $cleanupPaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousPath = getenv('PATH');
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanupPaths) as $path) {
            $this->cleanup($path);
        }
        if ($this->previousPath === false) {
            putenv('PATH');
        } else {
            putenv('PATH='.$this->previousPath);
        }
        parent::tearDown();
    }

    public function testSnapshotNvmeReturnsNullWhenBinaryMissing(): void
    {
        $device = $this->createFakeNvmeDevice();
        putenv('PATH=');

        $this->assertEquals(null, \pmssStorageHealthSnapshotNvme(['path' => $device], [], '2025-01-01T00:00:00+00:00'));
    }

    public function testSnapshotNvmeParsesMetricsAndFlags(): void
    {
        $device = $this->createFakeNvmeDevice();
        $this->installNvmeStub(implode("\n", [
            '#!/bin/sh',
            'cat <<\'EOF\'',
            'critical_warning : 1',
            'temperature : 343 K',
            'media_errors : 3',
            'num_err_log_entries : 9',
            'percentage_used : 96',
            'EOF',
        ])."\n");

        $entry = \pmssStorageHealthSnapshotNvme([
            'path' => $device,
            'kname' => 'nvme0n1',
            'model' => 'PMSS NVMe',
            'serial' => 'ABC123',
            'size' => '1T',
        ], [], '2025-01-01T00:00:00+00:00');

        $this->assertTrue(is_array($entry));
        $this->assertEquals('nvme', $entry['kind']);
        $this->assertEquals($device, $entry['device']);
        $this->assertEquals(1, $entry['metrics']['critical_warnings']);
        $this->assertEquals(70, $entry['metrics']['temperature']);
        $this->assertEquals(96, $entry['metrics']['percentage_used']);
        $this->assertEquals('fail', $entry['severity']);
        $this->assertTrue(!$entry['ok']);
        $this->assertEquals(['nvme_critical_warning', 'hot_nvme', 'wearout_critical'], $entry['flags']);
    }

    public function testSnapshotNvmeTracksMetricGrowthAgainstPreviousEntry(): void
    {
        $device = $this->createFakeNvmeDevice();
        $this->installNvmeStub(implode("\n", [
            '#!/bin/sh',
            'cat <<\'EOF\'',
            'critical_warning : 0',
            'temperature : 42 C',
            'media_errors : 5',
            'num_err_log_entries : 7',
            'percentage_used : 10',
            'EOF',
        ])."\n");

        $entry = \pmssStorageHealthSnapshotNvme(
            ['path' => $device],
            ['nvme::'.$device => ['metrics' => ['media_errors' => 2, 'num_err_log_entries' => 4]]],
            '2025-01-01T00:00:00+00:00'
        );

        $this->assertTrue(is_array($entry));
        $this->assertEquals('warn', $entry['severity']);
        $this->assertEquals(['media_errors_increase', 'err_log_increase'], $entry['flags']);
    }

    private function createFakeNvmeDevice(): string
    {
        $dir = sys_get_temp_dir().'/pmss-nvme-device-'.bin2hex(random_bytes(4));
        $path = $dir.'/dev-nvme0n1';
        @mkdir($dir, 0755, true);
        file_put_contents($path, 'device');
        $this->cleanupPaths[] = $dir;
        return $path;
    }

    private function installNvmeStub(string $script): void
    {
        $binDir = sys_get_temp_dir().'/pmss-nvme-bin-'.bin2hex(random_bytes(4));
        @mkdir($binDir, 0755, true);
        file_put_contents($binDir.'/nvme', $script);
        @chmod($binDir.'/nvme', 0755);
        $this->cleanupPaths[] = $binDir;

        $path = $binDir;
        if ($this->previousPath !== false && $this->previousPath !== '') {
            $path .= ':'.$this->previousPath;
        }
        putenv('PATH='.$path);
    }
}
