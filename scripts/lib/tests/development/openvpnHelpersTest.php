<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class OpenvpnHelpersTest extends TestCase
{
    public function testConfigureOpenvpnStillBuildsPmFqdnSlugAndHomeArtifactsInline(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/configureOpenvpn.php', [
            "strpos(\$hostname, '.pulsedmedia.com') !== false",
            "str_replace('.', '-', \$fqdn)",
            "'/home/openvpn-'.\$slug.'.ovpn'",
            "'/home/openvpn-'.\$slug.'.crt'",
        ]);
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
