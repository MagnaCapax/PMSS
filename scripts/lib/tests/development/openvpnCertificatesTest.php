<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/openvpn/certificates.php';

class OpenvpnCertificatesTest extends TestCase
{
    public function testMissingCertificateDoesNotRequestRenewal(): void
    {
        $plan = \pmssOpenvpnServerCertificateRenewalPlan($this->pmssMakeTempPath('pmss-openvpn-missing-'));

        $this->assertFalse($plan['renew']);
        $this->assertSame('missing', $plan['reason']);
        $this->assertSame(null, $plan['not_after']);
    }

    public function testInvalidCertificateDoesNotRequestRenewal(): void
    {
        $path = $this->pmssWriteFile($this->pmssMakeTempPath('pmss-openvpn-invalid-', '.crt'), "not a certificate\n");
        $plan = \pmssOpenvpnServerCertificateRenewalPlan($path);

        $this->assertFalse($plan['renew']);
        $this->assertSame('invalid', $plan['reason']);
    }

    public function testValidCertificateOutsideWindowKeepsFastPathEligible(): void
    {
        $path = $this->pmssWriteCertificateFixture();
        $notAfter = \pmssOpenvpnCertificateNotAfterTimestamp($path);
        $this->assertTrue(is_int($notAfter), 'fixture certificate must expose notAfter');

        $plan = \pmssOpenvpnServerCertificateRenewalPlan($path, $notAfter - 90000, 86400);
        $this->assertFalse($plan['renew']);
        $this->assertSame('valid', $plan['reason']);
    }

    public function testCertificateInsideWindowRequestsRenewal(): void
    {
        $path = $this->pmssWriteCertificateFixture();
        $notAfter = \pmssOpenvpnCertificateNotAfterTimestamp($path);
        $this->assertTrue(is_int($notAfter), 'fixture certificate must expose notAfter');

        $plan = \pmssOpenvpnServerCertificateRenewalPlan($path, $notAfter - 3600, 86400);
        $this->assertTrue($plan['renew']);
        $this->assertSame('expiring', $plan['reason']);
    }

    public function testExpiredCertificateRequestsRenewal(): void
    {
        $path = $this->pmssWriteCertificateFixture();
        $notAfter = \pmssOpenvpnCertificateNotAfterTimestamp($path);
        $this->assertTrue(is_int($notAfter), 'fixture certificate must expose notAfter');

        $plan = \pmssOpenvpnServerCertificateRenewalPlan($path, $notAfter + 1, 86400);
        $this->assertTrue($plan['renew']);
        $this->assertSame('expired', $plan['reason']);
    }

    public function testRenewalCommandsStayScopedToEasyRsaPki(): void
    {
        $backup = \pmssOpenvpnPkiBackupCommand('/etc/openvpn/easy-rsa', '/var/backups/pmss/config/openvpn', '20260713010203');
        $renew = \pmssOpenvpnServerCertificateRenewCommand('/etc/openvpn/easy-rsa');

        $this->assertStringContainsAllStrings([
            'install -d -m 0700',
            '/var/backups/pmss/config/openvpn',
            '20260713010203__etc_openvpn_easy-rsa_pki.tgz',
            'tar -C',
            'pki',
            'chmod 0600',
        ], $backup);
        $this->assertStringContainsAllStrings([
            './easyrsa help renew',
            './easyrsa --batch renew server nopass',
            'pki/issued/server.crt',
            'pki/private/server.key',
            'pki/reqs/server.req',
            './easyrsa --batch build-server-full server nopass',
        ], $renew);
    }

    public function testUnsafePathsRefuseCommandConstruction(): void
    {
        $this->assertSame('', \pmssOpenvpnPkiBackupCommand('relative/easy-rsa'));
        $this->assertSame('', \pmssOpenvpnPkiBackupCommand('/etc/openvpn/easy-rsa', 'relative/backups'));
        $this->assertSame('', \pmssOpenvpnServerCertificateRenewCommand("bad\0path"));
    }

    private function pmssWriteCertificateFixture(): string
    {
        if (!function_exists('openssl_pkey_new') || !function_exists('openssl_csr_new') || !function_exists('openssl_csr_sign')) {
            throw new SkipTest('PHP OpenSSL certificate generation unavailable');
        }

        $key = @openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $csr = $key !== false ? @openssl_csr_new(['commonName' => 'server'], $key) : false;
        $cert = $csr !== false ? @openssl_csr_sign($csr, null, $key, 2) : false;
        $pem = '';
        if ($cert === false || !@openssl_x509_export($cert, $pem)) {
            throw new SkipTest('PHP OpenSSL fixture certificate generation failed');
        }

        return $this->pmssWriteFile($this->pmssMakeTempPath('pmss-openvpn-cert-', '.crt'), $pem);
    }
}
