<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/update/opensslSsh2Compat.php';

class OpenSslSsh2CompatTest extends TestCase
{
    public function testOpenSshPackageSpecsPreserveInstallAndDownloadShapes(): void
    {
        $version = '1:9.2p1-2+deb12u7';

        $this->assertSame('openssh-server openssh-client openssh-sftp-server', \pmssOpenSslSsh2CompatOpenSshPackages());
        $this->assertSame('openssh-server=1:9.2p1-2+deb12u7 openssh-client=1:9.2p1-2+deb12u7 openssh-sftp-server=1:9.2p1-2+deb12u7', \pmssOpenSslSsh2CompatOpenSshPackages($version));
        $this->assertSame("openssh-server='1:9.2p1-2+deb12u7' openssh-client='1:9.2p1-2+deb12u7' openssh-sftp-server='1:9.2p1-2+deb12u7'", \pmssOpenSslSsh2CompatOpenSshPackages($version, true));
    }

    public function testPackageHoldMatchingRequiresExactLines(): void
    {
        $held = "libssl3\nopenssl\nnot-openssh-server\n";

        $this->assertTrue(\pmssOpenSslSsh2CompatPackageHeld($held, 'libssl3'));
        $this->assertTrue(\pmssOpenSslSsh2CompatPackageHeld($held, 'openssl'));
        $this->assertFalse(\pmssOpenSslSsh2CompatPackageHeld($held, 'openssh-server'));
    }

    public function testDebFilteringPreservesDeterministicPrefixSelection(): void
    {
        $debs = [
            '/tmp/runit-helper_2.16.2_all.deb',
            '/tmp/openssh-client_1%3a9.2p1-2+deb12u7_amd64.deb',
            '/tmp/openssh-server_1%3a9.2p1-2+deb12u7_amd64.deb',
            '/tmp/other_1.0_amd64.deb',
        ];

        $this->assertSame([
            '/tmp/runit-helper_2.16.2_all.deb',
        ], \pmssOpenSslSsh2CompatDebsByPrefix($debs, 'runit-helper'));
        $this->assertSame([], \pmssOpenSslSsh2CompatDebsByPrefix($debs, 'missing'));
        $this->assertSame([
            '/tmp/openssh-client_1%3a9.2p1-2+deb12u7_amd64.deb',
            '/tmp/openssh-server_1%3a9.2p1-2+deb12u7_amd64.deb',
        ], \pmssOpenSslSsh2CompatDebsByPrefix($debs, 'openssh'));
    }

    public function testDpkgInstallCommandKeepsConfPreserveFlagsAndDebOrder(): void
    {
        $command = \pmssOpenSslSsh2CompatDpkgInstallCommand([
            '/tmp/openssh-client.deb',
            '/tmp/openssh-server.deb',
        ]);

        $this->assertStringContainsString('dpkg --force-confdef --force-confold -i', $command);
        $this->assertStringContainsString("'/tmp/openssh-client.deb' '/tmp/openssh-server.deb'", $command);
    }

    public function testVersionQueryCommandUsesPayloadOnlyDpkgFormat(): void
    {
        $this->assertSame("dpkg-query -W -f='\${Version}' 'libssl3' 2>/dev/null", \pmssOpenSslSsh2CompatVersionQueryCommand('libssl3'));
    }

    public function testDpkgBaselineIsTheOnlyDeclarativeCompatibilityAuthority(): void
    {
        $baseline = $this->pmssReadRepoFile('scripts/lib/update/dpkg/selections-debian12.txt');
        foreach (['libssl3:amd64', 'openssl', 'openssh-client', 'openssh-server', 'openssh-sftp-server'] as $package) {
            $this->assertStringContainsString($package."\thold\n", $baseline);
        }

        $source = $this->pmssReadRepoFile('scripts/lib/update/opensslSsh2Compat.php');
        $this->assertStringNotContainsString('Pin-Priority', $source);
        $this->assertStringNotContainsString('preferences.d/pmss-libssl3-openssh.pref', $source);
    }
}
