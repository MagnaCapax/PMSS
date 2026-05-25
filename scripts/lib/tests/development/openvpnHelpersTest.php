<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class OpenvpnHelpersTest extends TestCase
{
    private function assertOpenvpnArtifactPathsRemainInlined(string $path, array $needles = []): void
    {
        $this->pmssAssertRepoFileContainsAllStrings($path, array_merge([
            "strpos(\$hostname, '.pulsedmedia.com') !== false",
            "str_replace('.', '-', \$fqdn)",
            "'/home/openvpn-'.\$slug.'.ovpn'",
            "'/home/openvpn-'.\$slug.'.crt'",
        ], $needles));
    }

    public function testConfigureOpenvpnStillBuildsPmFqdnSlugAndHomeArtifactsInline(): void
    {
        $this->assertOpenvpnArtifactPathsRemainInlined('scripts/util/configureOpenvpn.php');
    }

    public function testConfigureOpenvpnSkipRequiresSkelBundle(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/configureOpenvpn.php', [
            "\$bundleTgz    = '/etc/skel/www/openvpn-config.tgz';",
            '&& is_file($bundleTgz);',
            "escapeshellarg(\$bundleTgz)",
        ]);
    }

    public function testSystemTestUsesMatchingClientArtifactPaths(): void
    {
        $this->assertOpenvpnArtifactPathsRemainInlined('scripts/lib/systemStatus.php', [
            'function pmssSystemStatusChecks(',
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
