<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class SetupNetworkPortBlockTest extends TestCase
{
    public function testSetupNetworkBlocksDefaultTorrentWebUiPortsOnPublicInterface(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $path = $repoRoot.'/scripts/util/setupNetwork.php';
        $src = @file_get_contents($path);
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(strpos($src, "-A INPUT -i ##IFACE## -p tcp --dport 8080 -j DROP") !== false);
        $this->assertTrue(strpos($src, "-A INPUT -i ##IFACE## -p tcp --dport 8112 -j DROP") !== false);
    }

    public function testSetupNetworkLimitsTcpsackLogging(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $path = $repoRoot.'/scripts/util/setupNetwork.php';
        $src = @file_get_contents($path);
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(strpos($src, '--log-prefix "tcpsack: "') !== false);
        $this->assertTrue(strpos($src, '-m limit --limit 2/second --limit-burst 10') !== false);
    }

    public function testSetupNetworkLogsIpv4ForwardingWriteFailure(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $path = $repoRoot.'/scripts/util/setupNetwork.php';
        $src = @file_get_contents($path);
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);

        $this->assertTrue(strpos($src, "@file_put_contents('/proc/sys/net/ipv4/ip_forward', '1') === false") !== false);
        $this->assertTrue(strpos($src, "logMessage('setupNetwork: unable to enable IPv4 forwarding')") !== false);
    }
}
