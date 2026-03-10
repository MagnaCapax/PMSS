<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/netconsole.php';

class NetconsoleConfigureTest extends TestCase
{
    public function testParsesValidSpec(): void
    {
        $target = \pmssNetconsoleTargetFromSpec('6665@192.0.2.10/eth0,6666@192.0.2.20/aa:bb:cc:dd:ee:ff');
        $this->assertEquals('eth0', $target['interface']);
        $this->assertEquals('192.0.2.20', $target['targetIp']);
        $this->assertEquals('aa:bb:cc:dd:ee:ff', $target['targetMac']);
    }

    public function testRejectsSpecWithoutDeviceSegment(): void
    {
        $target = \pmssNetconsoleTargetFromSpec('6665@192.0.2.10,6666@192.0.2.20/aa:bb:cc:dd:ee:ff');
        $this->assertTrue($target === null, 'expected missing device segment to be rejected');
    }

    public function testSkipsWhenConfigMissing(): void
    {
        $dir = $this->makeTempDir('missing');
        $logs = [];
        $this->withNetconsoleEnv($dir, function () use (&$logs): void {
            \pmssNetconsoleConfigure(function (string $message) use (&$logs): void { $logs[] = $message; });
        });

        $this->assertTrue($this->contains($logs, 'No netconsole configuration'), 'expected missing-config skip log');
        $this->cleanup($dir);
    }

    public function testWritesFilesAndReloadsWhenReachable(): void
    {
        $dir = $this->makeTempDir('reachable');
        file_put_contents($dir.'/netconsole', '6665@192.0.2.10/eth0,6666@192.0.2.20/aa:bb:cc:dd:ee:ff');
        $calls = [];

        $this->withNetconsoleEnv($dir, function () use (&$calls): void {
            \pmssNetconsoleConfigure(function (): void {
            }, function (string $description, string $command) use (&$calls): int {
                $calls[] = [$description, $command];
                return 0;
            });
        });

        $this->assertTrue(is_file($dir.'/modprobe.d/netconsole.conf'), 'expected modprobe config');
        $this->assertTrue(is_file($dir.'/modules-load.d/pmss-netconsole.conf'), 'expected modules-load config');
        $this->assertEquals('Loading netconsole kernel module', $calls[1][0]);
        $this->cleanup($dir);
    }

    public function testSkipsEnableWhenTargetIsNotReachable(): void
    {
        $dir = $this->makeTempDir('unreachable');
        file_put_contents($dir.'/netconsole', '6665@192.0.2.10/eth0,6666@192.0.2.20/aa:bb:cc:dd:ee:ff');

        $this->withNetconsoleEnv($dir, function () use ($dir): void {
            \pmssNetconsoleConfigure(function (): void {
            }, function (): int {
                return 1;
            });
            $this->assertTrue(!is_file($dir.'/modprobe.d/netconsole.conf'), 'expected config write to be skipped');
        });

        $this->cleanup($dir);
    }

    public function testSkipsReloadWhenAlreadyLoadedAndUnchanged(): void
    {
        $dir = $this->makeTempDir('unchanged');
        $spec = '6665@192.0.2.10/eth0,6666@192.0.2.20/aa:bb:cc:dd:ee:ff';
        @mkdir($dir.'/modprobe.d', 0755, true);
        @mkdir($dir.'/modules-load.d', 0755, true);
        file_put_contents($dir.'/netconsole', $spec);
        file_put_contents($dir.'/modprobe.d/netconsole.conf', "options netconsole netconsole={$spec}\n");
        file_put_contents($dir.'/modules-load.d/pmss-netconsole.conf', "netconsole\n");
        $calls = [];

        $this->withNetconsoleEnv($dir, function () use (&$calls): void {
            putenv('PMSS_NETCONSOLE_MODULE_LOADED=1');
            \pmssNetconsoleConfigure(function (): void {
            }, function (string $description, string $command) use (&$calls): int {
                $calls[] = [$description, $command];
                return 0;
            });
        });

        $this->assertEquals(1, count($calls));
        $this->assertEquals('Verifying netconsole target reachability', $calls[0][0]);
        $this->cleanup($dir);
    }

    private function withNetconsoleEnv(string $dir, callable $callback): void
    {
        $env = [
            'PMSS_NETCONSOLE_CONFIG_PATH' => $dir.'/netconsole',
            'PMSS_NETCONSOLE_MODPROBE_PATH' => $dir.'/modprobe.d/netconsole.conf',
            'PMSS_NETCONSOLE_MODULES_LOAD_PATH' => $dir.'/modules-load.d/pmss-netconsole.conf',
            'PMSS_NETCONSOLE_MODULE_LOADED' => '',
        ];
        $previous = [];
        foreach ($env as $key => $value) {
            $previous[$key] = getenv($key);
            putenv($key.'='.$value);
        }
        try {
            $callback();
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === false) putenv($key);
                else putenv($key.'='.$value);
            }
        }
    }

    private function contains(array $messages, string $needle): bool
    {
        foreach ($messages as $message) {
            if (strpos($message, $needle) !== false) return true;
        }
        return false;
    }

    private function makeTempDir(string $suffix): string
    {
        $dir = sys_get_temp_dir().'/pmss-netconsole-'.bin2hex(random_bytes(4)).'-'.$suffix;
        @mkdir($dir, 0700, true);
        return $dir;
    }

    private function cleanup(string $path): void
    {
        if (!is_dir($path)) return;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) {
            if ($item->isDir()) @rmdir($item->getPathname());
            else @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
