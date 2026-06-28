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

    public function testPeerUserParserReportsUnavailableConfigStates(): void
    {
        $nonRegularConfig = $this->pmssMakeNamedTempDir('pmss-check-wireguard-', 0700).'/wg0.conf';
        mkdir($nonRegularConfig);

        foreach ([
            [$this->pmssMakeTempPath('pmss-check-wireguard-missing-', '.conf'), 'missing'],
            [$nonRegularConfig, 'not_regular'],
        ] as [$path, $status]) {
            $result = \pmssWireguardPeerUsersFromConfig($path);
            $this->assertSame($status, $result['status']);
            $this->assertSame([], $result['users']);
        }
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

    public function testUserPublicKeyReaderRejectsUnsafeBoundaries(): void
    {
        $homeBase = $this->pmssMakeNamedTempDir('pmss-check-wireguard-home-', 0700);
        $outsideDir = $this->pmssMakeNamedTempDir('pmss-check-wireguard-outside-', 0700);
        $unsafeUser = '../'.basename($outsideDir);
        $this->pmssWriteFile($homeBase.'/alice/.wireguard-public-key', $this->validPeerA."\n", 0700);
        $this->pmssWriteFile($outsideDir.'/.wireguard-public-key', $this->validPeerB."\n", 0700);

        $this->pmssWithEnv(['PMSS_WG_HOME_BASE' => $homeBase], function () use ($homeBase, $outsideDir, $unsafeUser): void {
            $this->assertSame([$this->validPeerA], \wgReadUserPublicKeys('alice'));
            $this->assertSame([], \wgReadUserPublicKeys($unsafeUser));

            $this->assertTrue(@unlink($homeBase.'/alice/.wireguard-public-key'));
            $this->assertTrue(@symlink($outsideDir.'/.wireguard-public-key', $homeBase.'/alice/.wireguard-public-key'));
            $this->assertSame([], \wgReadUserPublicKeys('alice'));
        });
    }

    public function testLsmodParserMatchesExactWireguardModuleOnly(): void
    {
        foreach ([
            ["wireguard 118784 0\n", true],
            ["wireguarded 118784 0\n", false],
            ["foo_wireguard 118784 0\n", false],
        ] as [$output, $expected]) {
            $this->assertSame($expected, \pmssWireguardLsmodOutputHasModule($output));
        }
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

    public function testMainHandlesPeerReconcileStates(): void
    {
        foreach ([
            [
                [$this->validPeerA], [], true,
                ['wg0 missing 1 configured peer(s), attempting syncconf', 'wg0 peer set reconciled with syncconf'],
                ['wg syncconf wg0 '],
                ['systemctl restart wg-quick@wg0'],
            ],
            [
                [$this->validPeerA], [$this->validPeerA], true,
                ['wg0 runtime peers match config'],
                [],
                ['wg syncconf wg0 ', 'systemctl restart wg-quick@wg0'],
            ],
            [
                [$this->validPeerA], [], false,
                ['wg peer reconcile failed during syncconf (rc=1), attempting restart', 'wg-quick@wg0 restarted successfully'],
                ['wg syncconf wg0 ', 'systemctl restart wg-quick@wg0'],
                [],
            ],
        ] as [$configuredPeers, $runningPeers, $syncSucceeds, $outputNeedles, $logRequired, $logForbidden]) {
            $this->assertWireguardMainRun($configuredPeers, $runningPeers, $syncSucceeds, $outputNeedles, $logRequired, $logForbidden);
        }
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

    /** Assert WireGuard main output and service command traces for one peer fixture. */
    private function assertWireguardMainRun(
        array $configuredPeers,
        array $runningPeers,
        bool $syncSucceeds,
        array $outputNeedles,
        array $logRequired,
        array $logForbidden
    ): void {
        $configPath = $this->writePeerConfig($configuredPeers);
        $logPath = $this->pmssMakeTempPath('pmss-check-wireguard-', '.log');
        $binDir = $this->writeWireguardCommandStubs($configPath, $logPath, $runningPeers, $syncSucceeds);

        $this->pmssWithPathPrefixedEnv($binDir, [
            'PMSS_TEST_MODE' => '1',
            'PMSS_WIREGUARD_CONFIG_PATH' => $configPath,
        ], function () use ($logPath, $outputNeedles, $logRequired, $logForbidden): void {
            list($rc, $output) = $this->pmssCaptureStdout(function (): int {
                return \pmssWireguardCheckMain(['checkWireguard.php', '--debug']);
            });

            $this->assertSame(0, $rc);
            $this->assertStringContainsAllStrings($outputNeedles, $output);
            $this->assertStringContainsAndOmitsStrings($logRequired, $logForbidden, (string) file_get_contents($logPath));
        });
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
