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
            $this->assertSame($expected, \pmssUserBillingServiceIdDigitsRead($home));
            $this->assertSame(fileowner($home.'/.billingServiceId') === 0 ? $expected : null, \pmssNginxUserBillingServiceIdFromHome($home));
        }
    }

    public function testBillingServiceIdFromHomeFallsBackToLegacyName(): void
    {
        $home = $this->pmssMakeTempDir('nginx-hosts-legacy-');
        file_put_contents($home.'/.billingId', "0008\n");

        $this->assertSame('0008', \pmssUserBillingServiceIdDigitsRead($home));
        $this->assertSame(fileowner($home.'/.billingId') === 0 ? '0008' : null, \pmssNginxUserBillingServiceIdFromHome($home));
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

    public function testMcxLabelsMatchPublishedVectors(): void
    {
        $vectors = json_decode((string) file_get_contents(__DIR__.'/../fixtures/mcx-label-vectors.json'), true);
        $this->assertTrue(is_array($vectors));
        foreach ($vectors as $vector) {
            $this->assertSame($vector['label'], \pmssMcxLabel($vector['kind'], $vector['id']));
            $hostname = $vector['kind'] === 'service'
                ? \pmssNginxUserMcxHostname($vector['id'])
                : \pmssNginxUserMcxClusterHostname($vector['id']);
            $this->assertSame($vector['label'].'.mcx.fi', $hostname);
        }
    }

    public function testMcxLabelRejectsInvalidInput(): void
    {
        foreach (['', 'other', 'Service'] as $kind) {
            $this->assertThrows(\InvalidArgumentException::class, static function () use ($kind): void { \pmssMcxLabel($kind, '1'); });
        }
        foreach (['', ' 1', '1 ', '-1', '1x', '1.0'] as $id) {
            $this->assertThrows(\InvalidArgumentException::class, static function () use ($id): void { \pmssMcxLabel('service', $id); });
        }
    }

    public function testMcxLabelCanonicalizesZeroesWithoutIntegerOverflow(): void
    {
        $this->assertSame(\pmssMcxLabel('service', '0'), \pmssMcxLabel('service', '0000'));
        $longId = '000'.str_repeat('9', 40);
        $this->assertSame(\pmssMcxLabel('customer', substr($longId, 3)), \pmssMcxLabel('customer', $longId));
    }

    public function testCustomerCertCommandMatchesServiceVectors(): void
    {
        $vectors = json_decode((string) file_get_contents(__DIR__.'/../fixtures/mcx-label-vectors.json'), true);
        $script = dirname(__DIR__, 4).'/etc/skel/bin/createWebPublicCerts';
        foreach ($vectors as $vector) {
            if ($vector['kind'] !== 'service') continue;
            $home = $this->pmssMakeTempDir('mcx-certs-home-');
            file_put_contents($home.'/.billingServiceId', $vector['id']);
            $result = $this->pmssExecShellCommand(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script), ['HOME' => $home]);
            $this->assertSame(0, $result['rc']);
            $this->assertTrue(strpos($result['output'], 'http://'.\pmssMcxLabel('service', $vector['id']).'.mcx.fi/') !== false);
            $this->assertTrue(strpos($result['output'], 'http://'.$vector['label'].'.mcx.fi/') !== false);
        }
    }
}
