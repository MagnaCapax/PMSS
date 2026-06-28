<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class WireGuardPeersStatusTest extends TestCase
{
    public function testStatusScriptReportsConfiguredPeersAndRuntimeCounters(): void
    {
        $configDir = $this->pmssMakeNamedTempDir('pmss-wireguard-status-', 0700);
        $configPath = $configDir.'/wg0.conf';
        $this->pmssWriteFile(
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
            // wg dump peer fields: pubkey, preshared-key, endpoint, allowed-ips,
            // latest-handshake, rx, tx, keepalive. The old fixture mirrored the
            // parser bug (endpoint in the preshared-key slot).
            "wg0\tserver\tserver\t51820\toff\n"
            ."keyAlice\t(none)\t198.51.100.10:51820\t10.90.90.2/32\t1700000000\t123\t456\t0\n"
            ."keyNoUser\t(none)\t\t10.90.90.3/32, 10.90.90.4/32\t0\t0\t0\t0\n"
        );

        $this->assertStringContainsString('USER', $output);
        $this->assertMatches('/alice\s+10\.90\.90\.2\s+yes\s+123\s+456\s+198\.51\.100\.10:51820/', $output);
        $this->assertMatches('/-\s+10\.90\.90\.3\s+no\s+0\s+0\s+-/', $output);
    }

    public function testStatusScriptReportsMissingConfigPath(): void
    {
        $configDir = $this->pmssMakeNamedTempDir('pmss-wireguard-status-', 0700);
        $output = $this->runScript($configDir, "");

        $this->assertStringContainsString('WireGuard config not found at '.$configDir.'/wg0.conf', $output);
        $this->assertStringContainsString('No WireGuard peers configured.', $output);
    }

    private function runScript(string $configDir, string $dumpOutput): string
    {
        $lines = $dumpOutput === '' ? [] : explode("\n", rtrim($dumpOutput, "\n"));
        $binDir = $this->pmssMakeLineOutputStub('wg', $lines, 'pmss-wireguard-status-');

        return $this->pmssRunRepoPhpScript(
            'scripts/wireguardPeersStatus.php',
            [],
            $this->pmssPathPrefixedEnvironment($binDir, [
                'PMSS_WG_CONFIG_DIR' => $configDir,
            ])
        );
    }
}
