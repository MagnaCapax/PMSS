<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class SetupNetworkPortBlockTest extends TestCase
{
    public function testSetupNetworkBlocksDefaultTorrentWebUiPortsOnPublicInterface(): void
    {
        $src = $this->pmssReadRepoFile('scripts/util/setupNetwork.php');

        $this->assertTrue(strpos($src, "-A INPUT -i ##IFACE## -p tcp --dport 8080 -j DROP") !== false);
        $this->assertTrue(strpos($src, "-A INPUT -i ##IFACE## -p tcp --dport 8112 -j DROP") !== false);
    }

    public function testSetupNetworkLimitsTcpsackLogging(): void
    {
        $src = $this->pmssReadRepoFile('scripts/util/setupNetwork.php');

        $this->assertTrue(strpos($src, '--log-prefix "tcpsack: "') !== false);
        $this->assertTrue(strpos($src, '-m limit --limit 2/second --limit-burst 10') !== false);
    }

    public function testSetupNetworkLogsIpv4ForwardingWriteFailure(): void
    {
        $src = $this->pmssReadRepoFile('scripts/util/setupNetwork.php');

        $this->assertTrue(strpos($src, "@file_put_contents('/proc/sys/net/ipv4/ip_forward', '1') === false") !== false);
        $this->assertTrue(strpos($src, "logMessage('setupNetwork: unable to enable IPv4 forwarding')") !== false);
    }
}
