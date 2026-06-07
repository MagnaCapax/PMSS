<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/cron/checkWireguard.php';

class CheckWireguardSafetyTest extends TestCase
{
    /** @var string */
    private $validPeerA = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    /** @var string */
    private $validPeerB = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=';

    public function testPeerUserParserFiltersInvalidUsersAndDeduplicates(): void
    {
        $configPath = $this->pmssMakeNamedTempDir('pmss-check-wireguard-', 0700).'/wg0.conf';
        file_put_contents(
            $configPath,
            "# user=alice\n"
            ."# user=bad.name\n"
            ."# user=bob\n"
            ."# user=alice\n"
            ."# user=toolongname\n"
        );

        $result = \pmssWireguardPeerUsersFromConfig($configPath);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(['alice', 'bob'], $result['users']);
    }

    public function testPeerUserParserReportsMissingConfig(): void
    {
        $result = \pmssWireguardPeerUsersFromConfig('/tmp/pmss-check-wireguard-missing/wg0.conf');

        $this->assertSame('missing', $result['status']);
        $this->assertSame([], $result['users']);
    }

    public function testPeerUserParserRefusesNonRegularConfig(): void
    {
        $configPath = $this->pmssMakeNamedTempDir('pmss-check-wireguard-', 0700).'/wg0.conf';
        mkdir($configPath);

        $result = \pmssWireguardPeerUsersFromConfig($configPath);

        $this->assertSame('not_regular', $result['status']);
        $this->assertSame([], $result['users']);
    }

    public function testPeerPublicKeyParserFiltersInvalidKeysAndDeduplicates(): void
    {
        $configPath = $this->pmssMakeNamedTempDir('pmss-check-wireguard-', 0700).'/wg0.conf';
        file_put_contents(
            $configPath,
            "[Peer]\n"
            ."PublicKey = {$this->validPeerA}\n"
            ."PublicKey = invalid\n"
            ."PublicKey = {$this->validPeerB}\n"
            ."PublicKey = {$this->validPeerA}\n"
        );

        $result = \pmssWireguardPeerPublicKeysFromConfig($configPath);

        $this->assertSame('ok', $result['status']);
        $this->assertSame([$this->validPeerA, $this->validPeerB], $result['keys']);
    }

    public function testLsmodParserMatchesExactWireguardModuleOnly(): void
    {
        $this->assertTrue(\pmssWireguardLsmodOutputHasModule("wireguard 118784 0\n"));
        $this->assertFalse(\pmssWireguardLsmodOutputHasModule("wireguarded 118784 0\n"));
        $this->assertFalse(\pmssWireguardLsmodOutputHasModule("foo_wireguard 118784 0\n"));
    }

    public function testMissingPeerPublicKeysReturnsConfiguredRuntimeDifference(): void
    {
        $this->assertSame(
            [$this->validPeerB],
            \pmssWireguardMissingPeerPublicKeys([$this->validPeerA, $this->validPeerB], [$this->validPeerA])
        );
    }

    public function testMainSkipsNonRegularConfigBeforeServiceActions(): void
    {
        $configPath = $this->pmssMakeNamedTempDir('pmss-check-wireguard-', 0700).'/wg0.conf';
        mkdir($configPath);

        $this->pmssWithEnv(['PMSS_WIREGUARD_CONFIG_PATH' => $configPath], function (): void {
            list($rc, $output) = $this->pmssCaptureStdout(function (): int {
                return \pmssWireguardCheckMain(['checkWireguard.php']);
            });

            $this->assertSame(0, $rc);
            $this->assertStringContainsString('wireguard config not_regular; skipping check', $output);
        });
    }

    public function testMainSyncsActiveInterfaceWhenConfiguredPeerIsMissing(): void
    {
        $configPath = $this->writePeerConfig([$this->validPeerA]);
        $logPath = $this->pmssMakeTempPath('pmss-check-wireguard-', '.log');
        $binDir = $this->writeWireguardCommandStubs($configPath, $logPath, [], true);

        $this->pmssWithPathPrefixedEnv($binDir, [
            'PMSS_TEST_MODE' => '1',
            'PMSS_WIREGUARD_CONFIG_PATH' => $configPath,
        ], function () use ($logPath): void {
            list($rc, $output) = $this->pmssCaptureStdout(function (): int {
                return \pmssWireguardCheckMain(['checkWireguard.php', '--debug']);
            });

            $this->assertSame(0, $rc);
            $this->assertStringContainsString('wg0 missing 1 configured peer(s), attempting syncconf', $output);
            $this->assertStringContainsString('wg0 peer set reconciled with syncconf', $output);
            $log = (string) file_get_contents($logPath);
            $this->assertStringContainsString('wg syncconf wg0 ', $log);
            $this->assertStringNotContainsString('systemctl restart wg-quick@wg0', $log);
        });
    }

    public function testMainSkipsSyncWhenRuntimePeersMatchConfig(): void
    {
        $configPath = $this->writePeerConfig([$this->validPeerA]);
        $logPath = $this->pmssMakeTempPath('pmss-check-wireguard-', '.log');
        $binDir = $this->writeWireguardCommandStubs($configPath, $logPath, [$this->validPeerA], true);

        $this->pmssWithPathPrefixedEnv($binDir, [
            'PMSS_TEST_MODE' => '1',
            'PMSS_WIREGUARD_CONFIG_PATH' => $configPath,
        ], function () use ($logPath): void {
            list($rc, $output) = $this->pmssCaptureStdout(function (): int {
                return \pmssWireguardCheckMain(['checkWireguard.php', '--debug']);
            });

            $this->assertSame(0, $rc);
            $this->assertStringContainsString('wg0 runtime peers match config', $output);
            $log = (string) file_get_contents($logPath);
            $this->assertStringNotContainsString('wg syncconf wg0 ', $log);
            $this->assertStringNotContainsString('systemctl restart wg-quick@wg0', $log);
        });
    }

    public function testMainFallsBackToRestartWhenSyncFails(): void
    {
        $configPath = $this->writePeerConfig([$this->validPeerA]);
        $logPath = $this->pmssMakeTempPath('pmss-check-wireguard-', '.log');
        $binDir = $this->writeWireguardCommandStubs($configPath, $logPath, [], false);

        $this->pmssWithPathPrefixedEnv($binDir, [
            'PMSS_TEST_MODE' => '1',
            'PMSS_WIREGUARD_CONFIG_PATH' => $configPath,
        ], function () use ($logPath): void {
            list($rc, $output) = $this->pmssCaptureStdout(function (): int {
                return \pmssWireguardCheckMain(['checkWireguard.php', '--debug']);
            });

            $this->assertSame(0, $rc);
            $this->assertStringContainsString('wg peer reconcile failed during syncconf (rc=1), attempting restart', $output);
            $this->assertStringContainsString('wg-quick@wg0 restarted successfully', $output);
            $log = (string) file_get_contents($logPath);
            $this->assertStringContainsString('wg syncconf wg0 ', $log);
            $this->assertStringContainsString('systemctl restart wg-quick@wg0', $log);
        });
    }

    /** @param array<int,string> $keys */
    private function writePeerConfig(array $keys): string
    {
        $configPath = $this->pmssMakeNamedTempDir('pmss-check-wireguard-', 0700).'/wg0.conf';
        $content = "[Interface]\nPrivateKey = server-private\nAddress = 10.90.90.1/24\n";
        foreach ($keys as $key) {
            $content .= "\n[Peer]\nPublicKey = {$key}\nAllowedIPs = 10.90.90.2/32\n";
        }
        $this->pmssWriteFile($configPath, $content, 0700);

        return $configPath;
    }

    /** @param array<int,string> $runningPeers */
    private function writeWireguardCommandStubs(
        string $configPath,
        string $logPath,
        array $runningPeers,
        bool $syncSucceeds
    ): string {
        $binDir = $this->pmssMakeNamedTempDir('pmss-check-wireguard-bin-', 0700);
        $wgScript = "#!/bin/sh\nprintf 'wg %s\\n' \"\$*\" >> ".escapeshellarg($logPath)."\n";
        $wgScript .= "if [ \"\$1 \$2 \$3\" = 'show wg0 peers' ]; then\n";
        foreach ($runningPeers as $peer) {
            $wgScript .= '  printf %s\\\\n '.escapeshellarg($peer)."\n";
        }
        $wgScript .= "  exit 0\nfi\n";
        $wgScript .= "if [ \"\$1 \$2\" = 'syncconf wg0' ]; then exit ".($syncSucceeds ? '0' : '1')."; fi\n";
        $wgScript .= "exit 1\n";

        return $this->pmssWriteExecutableFiles($binDir, [
            'lsmod' => "#!/bin/sh\nprintf 'wireguard 1 0\\n'\n",
            'modprobe' => "#!/bin/sh\nprintf 'modprobe %s\\n' \"\$*\" >> ".escapeshellarg($logPath)."\nexit 1\n",
            'systemctl' => "#!/bin/sh\nprintf 'systemctl %s\\n' \"\$*\" >> ".escapeshellarg($logPath)."\nif [ \"\$1 \$2 \$3\" = 'is-active --quiet wg-quick@wg0' ]; then exit 0; fi\nexit 0\n",
            'wg' => $wgScript,
            'wg-quick' => "#!/bin/sh\nprintf 'wg-quick %s\\n' \"\$*\" >> ".escapeshellarg($logPath)."\nif [ \"\$1 \$2\" = 'strip wg0' ]; then cat ".escapeshellarg($configPath)."; exit 0; fi\nexit 1\n",
        ], 0700);
    }
}
