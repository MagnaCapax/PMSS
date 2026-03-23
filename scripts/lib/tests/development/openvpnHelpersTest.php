<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class OpenvpnHelpersTest extends TestCase
{
    private function loadSource(string $relativePath): string
    {
        $path = dirname(__DIR__, 4).'/'.$relativePath;
        $contents = @file_get_contents($path);
        $this->assertTrue(is_string($contents) && $contents !== '', 'Unable to read '.$path);
        return $contents;
    }

    public function testConfigureOpenvpnStillBuildsPmFqdnAndSlugInline(): void
    {
        $contents = $this->loadSource('scripts/util/configureOpenvpn.php');

        $this->assertStringContainsString("strpos(\$hostname, '.pulsedmedia.com') !== false", $contents);
        $this->assertStringContainsString("str_replace('.', '-', \$fqdn)", $contents);
    }

    public function testConfigureOpenvpnClientArtifactsStayUnderHome(): void
    {
        $contents = $this->loadSource('scripts/util/configureOpenvpn.php');

        $this->assertStringContainsString("'/home/openvpn-'.\$slug.'.ovpn'", $contents);
        $this->assertStringContainsString("'/home/openvpn-'.\$slug.'.crt'", $contents);
    }

    public function testSystemTestUsesMatchingClientArtifactPaths(): void
    {
        $contents = $this->loadSource('scripts/lib/systemStatus.php');

        $this->assertStringContainsString('function pmssSystemStatusChecks(', $contents);
        $this->assertStringContainsString("strpos(\$hostname, '.pulsedmedia.com') !== false", $contents);
        $this->assertStringContainsString("str_replace('.', '-', \$fqdn)", $contents);
        $this->assertStringContainsString("'/home/openvpn-'.\$slug.'.ovpn'", $contents);
        $this->assertStringContainsString("'/home/openvpn-'.\$slug.'.crt'", $contents);
    }

    public function testSystemTestStillWarnsWhenHostnameIsUnknown(): void
    {
        $contents = $this->loadSource('scripts/lib/systemStatus.php');

        $this->assertStringContainsString("pmssStatus('OpenVPN client artifacts', 'WARN', 'hostname unknown')", $contents);
    }
}
