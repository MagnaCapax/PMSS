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

    public function testBillingIdFromFileAcceptsValidDigits(): void
    {
        $valid = [
            "123\n" => '123',
            "456" => '456',
            "0007\n" => '0007',
            "9" => '9',
            "10001" => '10001',
        ];

        foreach ($valid as $raw => $expected) {
            $path = $this->pmssWriteTempFile('nginx-hosts', $raw);
            $this->assertEquals($expected, \pmssNginxUserBillingIdFromFile($path));
        }
    }

    public function testBillingIdFromFileRejectsInvalidValues(): void
    {
        $invalid = [
            "",
            "0",
            "abc",
            "12a3",
            "   ",
        ];

        foreach ($invalid as $raw) {
            $path = $this->pmssWriteTempFile('nginx-hosts', $raw);
            $this->assertEquals(null, \pmssNginxUserBillingIdFromFile($path));
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

        foreach ($cases as [$user, $billingId, $host]) {
            $seed = $user.'.'.$billingId.'.'.$host;
            $expected = hash('sha256', $seed).'.'.$host;
            $this->assertEquals($expected, \pmssNginxUserHashHostname($user, $billingId, $host));
        }
    }
}
