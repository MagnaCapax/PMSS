<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/cron/checkWireguard.php';

class CheckWireguardSafetyTest extends TestCase
{
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
}
