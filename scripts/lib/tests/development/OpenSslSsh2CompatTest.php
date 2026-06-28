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

        $this->assertSame('/tmp/runit-helper_2.16.2_all.deb', \pmssOpenSslSsh2CompatFirstDebByPrefix($debs, 'runit-helper'));
        $this->assertSame('', \pmssOpenSslSsh2CompatFirstDebByPrefix($debs, 'missing'));
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

    public function testAptPinContentsPinCompatibleSetAtPriority1001(): void
    {
        $contents = \pmssOpenSslSsh2CompatAptPinContents();

        // libssl3/openssl pinned to the held target, OpenSSH trio to deb12u7, both at 1001.
        $this->assertStringContainsString("Package: libssl3 openssl\nPin: version ".PMSS_OPENSSL_SSH2_LIBSSL_TARGET."\nPin-Priority: 1001", $contents);
        $this->assertStringContainsString("Package: ".\pmssOpenSslSsh2CompatOpenSshPackages()."\nPin: version ".PMSS_OPENSSL_SSH2_OPENSSH_TARGET."\nPin-Priority: 1001", $contents);
        // Pin-Priority must be >1000 to force downgrade-not-remove; 1001 appears for both stanzas.
        $this->assertSame(2, substr_count($contents, 'Pin-Priority: 1001'));
        // Derived from shared constants (DRY) — no hardcoded version drift.
        $this->assertStringContainsString(PMSS_OPENSSL_SSH2_OPENSSH_TARGET, $contents);
    }
}
