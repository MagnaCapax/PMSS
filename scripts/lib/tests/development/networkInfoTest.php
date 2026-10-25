<?php
namespace PMSS\Tests;

// Sanity tests for scripts/lib/networkInfo.php helpers
require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/networkInfo.php';

class NetworkInfoTest extends TestCase
{
    private function networkSpeedFixture(string $iface, string $speed): string
    {
        $root = $this->pmssMakeTempDir('pmss-network-sys-class-net-');
        $this->pmssEnsureDir($root.'/'.$iface);
        file_put_contents($root.'/'.$iface.'/speed', $speed);
        return $root;
    }

    private function withNetworkConfig(array $config, callable $callback, array $env = []): void
    {
        $path = $this->pmssWritePhpArrayFixture($config, 'pmss-network-info-');
        $this->pmssWithEnv(array_merge(['PMSS_NETWORK_CONFIG' => $path], $env), $callback);
    }

    public function testNetworkInterfaceNameNormalizedKeepsSafeNames(): void
    {
        $this->assertSame('bond0.100', \networkInterfaceNameNormalized(' bond0.100 '));
    }

    public function testNetworkInterfaceNameNormalizedRejectsUnsafeNames(): void
    {
        $this->assertSame('', \networkInterfaceNameNormalized('eth0; touch /tmp/pwned'));
    }

    public function testNetworkInterfaceNamesRejectNullBytesBeforeTrimming(): void
    {
        foreach (["\0eth0", "eth0\0", "eth\0".'0', " \0eth0\t", "eth0\0\n"] as $name) {
            $this->assertSame('', \networkInterfaceNameNormalized($name));
            $this->assertSame('', \pmssNetworkInterfaceNameNormalize($name, 15));
            $this->assertFalse(\pmssNetworkInterfaceNameIsSafe($name));
            $this->assertSame(0, \pmssNetworkProbeSysfsLinkSpeed($name));
            $this->assertSame(0, \pmssNetworkProbeEthtoolLinkSpeed($name));
        }
        foreach (['eth0', 'bond0.100', 'veth_test-1', 'eth0:1', 'lo'] as $name) {
            $this->assertSame($name, \networkInterfaceNameNormalized(" \t".$name."\r\n"));
        }
        $this->assertSame('', \pmssNetworkInterfaceNameNormalize('bond0.100', 8));
        $this->assertSame('bond0.100', \pmssNetworkInterfaceNameNormalize('bond0.100', 9));
    }

    public function testSysfsSpeedRejectsOverflowAndPreservesRepresentableValues(): void
    {
        foreach (['', '-1', '0', 'unknown', '1.5', (string) PHP_INT_MAX.'0', '000'.PHP_INT_MAX.'0'] as $raw) {
            $root = $this->networkSpeedFixture('probe0', $raw);
            $this->pmssWithEnv(['PMSS_NETWORK_SYS_CLASS_NET_DIR' => $root], function (): void {
                $this->assertSame(0, \pmssNetworkProbeSysfsLinkSpeed('probe0'));
                $this->assertSame(0, \getDetectedLinkSpeed('probe0'));
            });
        }
        foreach (['1' => 1, '001000' => 1000, " 10000\n" => 10000, (string) PHP_INT_MAX => PHP_INT_MAX] as $raw => $expected) {
            $root = $this->networkSpeedFixture('probe0', (string) $raw);
            $this->pmssWithEnv(['PMSS_NETWORK_SYS_CLASS_NET_DIR' => $root], function () use ($expected): void {
                $this->assertSame($expected, \pmssNetworkProbeSysfsLinkSpeed('probe0'));
            });
        }
    }

    public function testDetectPrimaryInterfaceReturnsString(): void
    {
        $iface = \detectPrimaryInterface();
        $this->assertTrue(is_string($iface));
        $this->assertTrue($iface !== '');
    }

    public function testGetLinkSpeedReturnsInt(): void
    {
        $iface = \detectPrimaryInterface();
        $speed = \getLinkSpeed($iface);
        $this->assertTrue(is_int($speed));
        $this->assertTrue($speed > 0);
    }

    public function testDetectPrimaryInterfaceUsesSharedConfigOverride(): void
    {
        $this->withNetworkConfig(['interface' => 'bond9'], function (): void {
            $this->assertSame('bond9', \detectPrimaryInterface());
        });
    }

    public function testGetLinkSpeedUsesSharedConfigOverride(): void
    {
        $this->withNetworkConfig(['speed' => '4321'], function (): void {
            $this->assertSame(4321, \getLinkSpeed('eth0'));
        });
    }

    public function testDetectedLinkSpeedReadsSysfsFixture(): void
    {
        $root = $this->networkSpeedFixture('eth0', '10000');

        $this->pmssWithEnv(['PMSS_NETWORK_SYS_CLASS_NET_DIR' => $root], function (): void {
            $this->assertSame(10000, \getDetectedLinkSpeed('eth0'));
        });
    }

    public function testDetectedLinkSpeedTreatsUnknownSysfsSpeedAsNoDetection(): void
    {
        $root = $this->networkSpeedFixture('eth0', '-1');

        $this->pmssWithEnv(['PMSS_NETWORK_SYS_CLASS_NET_DIR' => $root], function (): void {
            $this->assertSame(0, \getDetectedLinkSpeed('eth0'));
        });
    }

    public function testConfiguredSpeedRemainsAuthoritativeWhenPhysicalSpeedIsHigher(): void
    {
        $root = $this->networkSpeedFixture('eth0', '10000');

        $this->withNetworkConfig(['speed' => '1000'], function (): void {
            $this->assertSame(1000, \getLinkSpeed('eth0'));
            $this->assertSame(10000, \getDetectedLinkSpeed('eth0'));
        }, ['PMSS_NETWORK_SYS_CLASS_NET_DIR' => $root]);
    }

    public function testGetLinkSpeedFallsBackForUnsafeInterfaceName(): void
    {
        $this->assertSame(1000, \getLinkSpeed('eth0 && rm -rf /'));
        $this->assertSame(0, \getDetectedLinkSpeed('eth0 && rm -rf /'));
    }

    public function testDetectPrimaryInterfaceIgnoresUnsafeConfigOverride(): void
    {
        $this->withNetworkConfig(['interface' => 'eth0; touch /tmp/pwned'], function (): void {
            $iface = \detectPrimaryInterface();
            $this->assertTrue($iface !== '');
            $this->assertSame('', \networkInterfaceNameNormalized('eth0; touch /tmp/pwned'));
            $this->assertTrue($iface !== 'eth0; touch /tmp/pwned');
        });
    }
}
