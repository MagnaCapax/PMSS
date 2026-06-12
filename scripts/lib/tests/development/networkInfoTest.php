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

    public function testNetworkInterfaceNameNormalizedKeepsSafeNames(): void
    {
        $this->assertSame('bond0.100', \networkInterfaceNameNormalized(' bond0.100 '));
    }

    public function testNetworkInterfaceNameNormalizedRejectsUnsafeNames(): void
    {
        $this->assertSame('', \networkInterfaceNameNormalized('eth0; touch /tmp/pwned'));
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
        $tmp = $this->pmssWritePhpArrayFixture(['interface' => 'bond9'], 'pmss-network-info-');

        $this->pmssWithEnv(['PMSS_NETWORK_CONFIG' => $tmp], function (): void {
            $this->assertSame('bond9', \detectPrimaryInterface());
        });
    }

    public function testGetLinkSpeedUsesSharedConfigOverride(): void
    {
        $tmp = $this->pmssWritePhpArrayFixture(['speed' => '4321'], 'pmss-network-info-');

        $this->pmssWithEnv(['PMSS_NETWORK_CONFIG' => $tmp], function (): void {
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
        $config = $this->pmssWritePhpArrayFixture(['speed' => '1000'], 'pmss-network-info-');
        $root = $this->networkSpeedFixture('eth0', '10000');

        $this->pmssWithEnv([
            'PMSS_NETWORK_CONFIG' => $config,
            'PMSS_NETWORK_SYS_CLASS_NET_DIR' => $root,
        ], function (): void {
            $this->assertSame(1000, \getLinkSpeed('eth0'));
            $this->assertSame(10000, \getDetectedLinkSpeed('eth0'));
        });
    }

    public function testGetLinkSpeedFallsBackForUnsafeInterfaceName(): void
    {
        $this->assertSame(1000, \getLinkSpeed('eth0 && rm -rf /'));
        $this->assertSame(0, \getDetectedLinkSpeed('eth0 && rm -rf /'));
    }

    public function testDetectPrimaryInterfaceIgnoresUnsafeConfigOverride(): void
    {
        $tmp = $this->pmssWritePhpArrayFixture(['interface' => 'eth0; touch /tmp/pwned'], 'pmss-network-info-');

        $this->pmssWithEnv(['PMSS_NETWORK_CONFIG' => $tmp], function (): void {
            $iface = \detectPrimaryInterface();
            $this->assertTrue($iface !== '');
            $this->assertSame('', \networkInterfaceNameNormalized('eth0; touch /tmp/pwned'));
            $this->assertTrue($iface !== 'eth0; touch /tmp/pwned');
        });
    }
}
