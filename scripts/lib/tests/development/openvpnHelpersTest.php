<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class OpenvpnHelpersTest extends TestCase
{
    public function testConfigureOpenvpnStillBuildsPmFqdnAndSlugInline(): void
    {
        $contents = $this->pmssReadRepoFile('scripts/util/configureOpenvpn.php');

        $this->assertStringContainsString("strpos(\$hostname, '.pulsedmedia.com') !== false", $contents);
        $this->assertStringContainsString("str_replace('.', '-', \$fqdn)", $contents);
    }

    public function testConfigureOpenvpnClientArtifactsStayUnderHome(): void
    {
        $contents = $this->pmssReadRepoFile('scripts/util/configureOpenvpn.php');

        $this->assertStringContainsString("'/home/openvpn-'.\$slug.'.ovpn'", $contents);
        $this->assertStringContainsString("'/home/openvpn-'.\$slug.'.crt'", $contents);
    }

    public function testSystemTestUsesMatchingClientArtifactPaths(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/systemStatus.php', [
            'function pmssSystemStatusChecks(',
            "strpos(\$hostname, '.pulsedmedia.com') !== false",
            "str_replace('.', '-', \$fqdn)",
            "'/home/openvpn-'.\$slug.'.ovpn'",
            "'/home/openvpn-'.\$slug.'.crt'",
        ]);
    }

    public function testSystemTestStillWarnsWhenHostnameIsUnknown(): void
    {
        $this->pmssAssertRepoFileContainsString(
            'scripts/lib/systemStatus.php',
            "pmssStatus('OpenVPN client artifacts', 'WARN', 'hostname unknown')"
        );
    }
}
