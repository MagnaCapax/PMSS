<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/lib/nginxUserHosts.php';

/**
 * Hermetic tests for nginx user subdomain helpers.
 */
class NginxUserHostsTest extends TestCase
{
    public function testHostIsValidFqdnAcceptsExpectedNames(): void
    {
        $valid = [
            'seedbox.example.com',
            'a.b',
            'node-1.example.net',
            'lt5-1-56-138anger-core.pulsedmedia.com',
            'mix3d-labels.example.org',
        ];

        foreach ($valid as $hostname) {
            $this->assertTrue(
                \pmssNginxUserHostIsValidFqdn($hostname),
                'expected hostname to be valid: '.$hostname
            );
        }
    }

    public function testHostIsValidFqdnRejectsInvalidNames(): void
    {
        $invalid = [
            '',
            'localhost',
            'host..name',
            'host.example.',
            '192.168.1.10',
            'host_underscore.example',
        ];

        foreach ($invalid as $hostname) {
            $this->assertTrue(
                !\pmssNginxUserHostIsValidFqdn($hostname),
                'expected hostname to be invalid: '.$hostname
            );
        }
    }

    public function testBillingServiceIdFromHomeAcceptsValidDigits(): void
    {
        $valid = [
            "123\n" => '123',
            "456" => '456',
            "0007\n" => '0007',
            "9" => '9',
            "10001" => '10001',
        ];

        foreach ($valid as $raw => $expected) {
            $home = $this->pmssMakeTempDir('nginx-hosts-home-');
            file_put_contents($home.'/.billingServiceId', $raw);
            $this->assertEquals($expected, \pmssNginxUserBillingServiceIdFromHome($home));
        }
    }

    public function testBillingServiceIdFromHomeFallsBackToLegacyName(): void
    {
        $home = $this->pmssMakeTempDir('nginx-hosts-legacy-');
        file_put_contents($home.'/.billingId', "0008\n");

        $this->assertEquals('0008', \pmssNginxUserBillingServiceIdFromHome($home));
    }

    public function testBillingServiceIdFromHomeRejectsInvalidValues(): void
    {
        $invalid = [
            "",
            "0",
            "abc",
            "12a3",
            "   ",
        ];

        foreach ($invalid as $raw) {
            $home = $this->pmssMakeTempDir('nginx-hosts-invalid-');
            file_put_contents($home.'/.billingServiceId', $raw);
            $this->assertEquals(null, \pmssNginxUserBillingServiceIdFromHome($home));
        }
    }

    public function testHashHostnameMatchesExpected(): void
    {
        $cases = [
            ['alice', '123', 'seedbox.example.com'],
            ['bob', '999', 'node.example.net'],
            ['user1', '4567', 'host.domain.tld'],
            ['zeus', '42', 'alpha.beta'],
            ['test', '10001', 'srv.example.org'],
        ];

        foreach ($cases as [$user, $billingServiceId, $host]) {
            $seed = $user.'.'.$billingServiceId.'.'.$host;
            $expected = hash('sha256', $seed).'.'.$host;
            $this->assertEquals($expected, \pmssNginxUserHashHostname($user, $billingServiceId, $host));
        }
    }
}
