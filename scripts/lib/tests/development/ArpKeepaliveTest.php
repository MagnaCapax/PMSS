<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/arpKeepalive.php';

class ArpKeepaliveTest extends TestCase
{
    private const ARP_HEADER = "IP address       HW type     Flags       HW address            Mask     Device\n";

    private function marker(?string $content): string
    {
        $dir = $this->pmssMakeTempDir('pmss-arp-keepalive-');
        $path = $dir.'/arp-keepalive';
        if ($content !== null) {
            file_put_contents($path, $content);
        }
        return $path;
    }

    private function runKeepalive(string $marker, string $route, string $arp, bool &$sent = null): string
    {
        $sent = false;
        return \pmssArpKeepaliveRun(
            $marker,
            static function (string $ip) use ($route): string { return $route; },
            static function () use ($arp): string { return $arp; },
            static function (string $ip) use (&$sent): bool { $sent = true; return true; }
        );
    }

    public function testNoMarkerIsSilentAndSendsNothing(): void
    {
        $out = $this->runKeepalive($this->marker(null), "10.0.0.9 dev eth0 src 10.0.0.2\n", self::ARP_HEADER, $sent);
        $this->assertEquals('', $out);
        $this->assertFalse($sent);
    }

    public function testInvalidMarkerContentIsRejectedWithoutSending(): void
    {
        foreach (['not-an-ip', '10.0.0.9; rm -rf /', "10.0.0.9\n10.0.0.8", '::1', '999.1.1.1', ''] as $bad) {
            $out = $this->runKeepalive($this->marker($bad), "10.0.0.9 dev eth0\n", self::ARP_HEADER, $sent);
            $this->assertTrue(strpos($out, 'skip: marker does not hold a single IPv4 address') !== false, 'rejects '.json_encode($bad));
            $this->assertFalse($sent);
        }
    }

    public function testOffLinkTargetIsSkipped(): void
    {
        $out = $this->runKeepalive($this->marker("10.0.0.9\n"), "10.0.0.9 via 10.0.0.1 dev eth0 src 10.0.0.2\n", self::ARP_HEADER, $sent);
        $this->assertTrue(strpos($out, 'is not on-link') !== false);
        $this->assertFalse($sent);
    }

    public function testEmptyRouteOutputIsTreatedAsNotOnLink(): void
    {
        $this->assertFalse(\pmssArpKeepaliveIsOnLink(''));
    }

    public function testAnsweringTargetWarnsAndDoesNotSend(): void
    {
        $arp = self::ARP_HEADER."10.0.0.9         0x1         0x2         52:54:00:aa:bb:cc     *        eth0\n";
        $out = $this->runKeepalive($this->marker('10.0.0.9'), "10.0.0.9 dev eth0 src 10.0.0.2\n", $arp, $sent);
        $this->assertTrue(strpos($out, 'WARN: target 10.0.0.9 answers') !== false);
        $this->assertFalse($sent);
    }

    public function testUnansweredOnLinkTargetSendsOneDatagram(): void
    {
        $arp = self::ARP_HEADER."10.0.0.9         0x1         0x0         00:00:00:00:00:00     *        eth0\n";
        $out = $this->runKeepalive($this->marker(" 10.0.0.9 \n"), "10.0.0.9 dev eth0 src 10.0.0.2\n", $arp, $sent);
        $this->assertTrue($sent);
        $this->assertTrue(strpos($out, 'arp-keepalive sent target=10.0.0.9 neighbour=incomplete') !== false);
    }

    public function testNeighbourStateMatchesExactAddressOnly(): void
    {
        $arp = self::ARP_HEADER."10.0.0.99        0x1         0x2         52:54:00:aa:bb:cc     *        eth0\n";
        $this->assertEquals('absent', \pmssArpKeepaliveNeighbourState($arp, '10.0.0.9'));
    }

    public function testCronAndLogrotateAreWired(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'etc/seedbox/config/root.cron' => ['required' => ['*/10 * * * *   root /scripts/cron/arpKeepalive.php >> /var/log/pmss/arpKeepalive.log 2>&1']],
            'etc/seedbox/config/template.logrotate.pmss' => ['required' => ['/var/log/pmss/arpKeepalive.log {', 'maxsize 16M']],
            'scripts/cron/arpKeepalive.php' => ['required' => ["require_once __DIR__.'/../lib/arpKeepalive.php';", 'escapeshellarg($ip)']],
        ]);
    }
}
