<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
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

    public function testRejectsSpecWithUnsafeInterfaceName(): void
    {
        $target = \pmssNetconsoleTargetFromSpec('6665@192.0.2.10/eth0 bad,6666@192.0.2.20/aa:bb:cc:dd:ee:ff');
        $this->assertTrue($target === null, 'expected whitespace-bearing interface to be rejected');
    }

    public function testSkipsWhenConfigMissing(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-netconsole-missing-');
        $logs = [];
        $this->pmssWithEnv($this->netconsoleEnv($dir), function () use (&$logs): void {
            \pmssNetconsoleConfigure(function (string $message) use (&$logs): void { $logs[] = $message; });
        });

        $this->assertTrue($this->pmssMessagesContain($logs, 'No netconsole configuration'), 'expected missing-config skip log');
    }

    public function testWritesFilesAndReloadsWhenReachable(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-netconsole-reachable-');
        file_put_contents($dir.'/netconsole', '6665@192.0.2.10/eth0,6666@192.0.2.20/aa:bb:cc:dd:ee:ff');
        $calls = [];

        $this->pmssWithEnv($this->netconsoleEnv($dir), function () use (&$calls): void {
            \pmssNetconsoleConfigure(function (): void {
            }, function (string $description, string $command) use (&$calls): int {
                $calls[] = [$description, $command];
                return 0;
            });
        });

        $this->assertTrue(is_file($dir.'/modprobe.d/netconsole.conf'), 'expected modprobe config');
        $this->assertTrue(is_file($dir.'/modules-load.d/pmss-netconsole.conf'), 'expected modules-load config');
        $descriptions = array_map(static function (array $call): string {
            return $call[0];
        }, $calls);
        foreach ($calls as $call) {
            if (strpos($call[0], 'netconsole target') === false) {
                continue;
            }
            $this->assertStringNotContainsString(';', $call[1], 'target probe must avoid command chaining');
            $this->assertStringNotContainsString('||', $call[1], 'target probe must avoid conditional chaining');
        }
        $this->assertTrue(in_array('Loading netconsole kernel module', $descriptions, true), 'expected netconsole module load step');
    }

    public function testSkipsEnableWhenTargetIsNotReachable(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-netconsole-unreachable-');
        file_put_contents($dir.'/netconsole', '6665@192.0.2.10/eth0,6666@192.0.2.20/aa:bb:cc:dd:ee:ff');

        $this->pmssWithEnv($this->netconsoleEnv($dir), function () use ($dir): void {
            \pmssNetconsoleConfigure(function (): void {
            }, function (): int {
                return 1;
            });
            $this->assertTrue(!is_file($dir.'/modprobe.d/netconsole.conf'), 'expected config write to be skipped');
        });
    }

    public function testSkipsReloadWhenAlreadyLoadedAndUnchanged(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-netconsole-unchanged-');
        $spec = '6665@192.0.2.10/eth0,6666@192.0.2.20/aa:bb:cc:dd:ee:ff';
        @mkdir($dir.'/modprobe.d', 0755, true);
        @mkdir($dir.'/modules-load.d', 0755, true);
        file_put_contents($dir.'/netconsole', $spec);
        file_put_contents($dir.'/modprobe.d/netconsole.conf', "options netconsole netconsole={$spec}\n");
        file_put_contents($dir.'/modules-load.d/pmss-netconsole.conf', "netconsole\n");
        $calls = [];

        $env = $this->netconsoleEnv($dir);
        $env['PMSS_NETCONSOLE_MODULE_LOADED'] = '1';
        $this->pmssWithEnv($env, function () use (&$calls): void {
            \pmssNetconsoleConfigure(function (): void {
            }, function (string $description, string $command) use (&$calls): int {
                $calls[] = [$description, $command];
                return 0;
            });
        });

        $this->assertEquals(2, count($calls));
        $this->assertEquals('Priming netconsole target neighbor cache', $calls[0][0]);
        $this->assertEquals('Verifying netconsole target reachability', $calls[1][0]);
    }

    private function netconsoleEnv(string $dir): array
    {
        return [
            'PMSS_NETCONSOLE_CONFIG_PATH' => $dir.'/netconsole',
            'PMSS_NETCONSOLE_MODPROBE_PATH' => $dir.'/modprobe.d/netconsole.conf',
            'PMSS_NETCONSOLE_MODULES_LOAD_PATH' => $dir.'/modules-load.d/pmss-netconsole.conf',
            'PMSS_NETCONSOLE_MODULE_LOADED' => '',
        ];
    }

}
