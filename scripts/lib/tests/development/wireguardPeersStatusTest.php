<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class WireGuardPeersStatusTest extends TestCase
{
    /** @var array<int,string> */
    private $cleanupPaths = array();

    public function __destruct()
    {
        foreach ($this->cleanupPaths as $path) {
            $this->removePath($path);
        }
    }

    public function testStatusScriptReportsConfiguredPeersAndRuntimeCounters(): void
    {
        $configDir = $this->createTempDir();
        $configPath = $configDir.'/wg0.conf';
        file_put_contents(
            $configPath,
            "[Interface]\n"
            ."Address = 10.90.90.1/24\n"
            ."\n"
            ."[Peer]\n"
            ."# user=alice\n"
            ."PublicKey = keyAlice\n"
            ."AllowedIPs = 10.90.90.2/32\n"
            ."\n"
            ."[Peer]\n"
            ."PublicKey = keyNoUser\n"
            ."AllowedIPs = 10.90.90.3/32, 10.90.90.4/32\n"
        );

        $output = $this->runScript(
            $configDir,
            "wg0\tserver\tserver\t51820\toff\n"
            ."keyAlice\t198.51.100.10:51820\tunused\tunused\t1700000000\t123\t456\t0\n"
            ."keyNoUser\t\tunused\tunused\t0\t0\t0\t0\n"
        );

        $this->assertStringContainsString('USER', $output);
        $this->assertMatches('/alice\s+10\.90\.90\.2\s+yes\s+123\s+456\s+198\.51\.100\.10:51820/', $output);
        $this->assertMatches('/-\s+10\.90\.90\.3\s+no\s+0\s+0\s+-/', $output);
    }

    public function testStatusScriptReportsMissingConfigPath(): void
    {
        $configDir = $this->createTempDir();
        $output = $this->runScript($configDir, "");

        $this->assertStringContainsString('WireGuard config not found at '.$configDir.'/wg0.conf', $output);
        $this->assertStringContainsString('No WireGuard peers configured.', $output);
    }

    private function runScript(string $configDir, string $dumpOutput): string
    {
        $binDir = $this->createTempDir().'/bin';
        @mkdir($binDir, 0755, true);

        $wgScript = "#!/bin/sh\n";
        if ($dumpOutput !== '') {
            foreach (explode("\n", rtrim($dumpOutput, "\n")) as $line) {
                $wgScript .= "printf '%s\\n' '".str_replace("'", "'\\''", $line)."'\n";
            }
        }
        $wgScript .= "exit 0\n";
        $wgPath = $binDir.'/wg';
        @file_put_contents($wgPath, $wgScript);
        @chmod($wgPath, 0755);

        $path = getenv('PATH');
        $path = ($path === false || $path === '') ? $binDir : $binDir.':'.$path;
        $command = 'PMSS_WG_CONFIG_DIR='.escapeshellarg($configDir)
            .' PATH='.escapeshellarg($path)
            .' '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg(dirname(__DIR__, 3).'/wireguardPeersStatus.php')
            .' 2>&1';

        return (string) shell_exec($command);
    }

    private function createTempDir(): string
    {
        $dir = $this->pmssMakeNamedTempDir('pmss-wireguard-status-', 0700);
        $this->cleanupPaths[] = $dir;
        return $dir;
    }

    private function removePath(string $path): void
    {
        $this->cleanup($path);
    }
}
