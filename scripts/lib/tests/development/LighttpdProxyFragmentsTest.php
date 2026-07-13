<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/util/userConfigLighttpd.php';

class LighttpdProxyFragmentsTest extends TestCase
{
    private function managedProxyFragments(): array
    {
        return [
            'rclone' => \pmssLighttpdManagedProxyFragment('rclone', 'demo', 4001),
            'qbittorrent' => \pmssLighttpdManagedProxyFragment('qbittorrent', 'demo', 4002),
            'invidious' => \pmssLighttpdManagedProxyFragment('invidious', 'demo', 4003),
            'deluge' => \pmssLighttpdManagedProxyFragment('deluge', 'demo', 4004),
        ];
    }

    public function testTemplateDoesNotEmbedManagedProxyPaths(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.lighttpd');

        foreach ([
            '/user-##username/rclone/' => 'rclone proxy must be in custom fragment',
            '/user-##username/qbittorrent/' => 'qBittorrent proxy must be in custom fragment',
        ] as $needle => $message) {
            $this->assertStringNotContainsString($needle, $template, $message);
        }
    }

    public function testTemplateLoadsRedirectModuleForManagedProxyRedirects(): void
    {
        $this->pmssAssertRepoFileContainsString('etc/seedbox/config/template.lighttpd', '"mod_redirect",');
    }

    public function testManagedProxyFragmentsMatchCharacterization(): void
    {
        $expectedHashes = [
            'rclone' => '6d265ad8b338c69f39057129c0cd1a9a7bca09b3270ccea555168ceca96104de',
            'qbittorrent' => '21585cc7a5b993589046f2b529ed6e03a41fd70386f9d0512f9d7ed948c9009f',
            'invidious' => '168bb1b5e71c3ea4af59cf176a2181b00f425485dd504cb3ca088a8c73a178b4',
            'deluge' => '485c54359bd2220fe74b10ff06e371d4e5aea69b53130eab71d845cb8968c822',
        ];

        foreach ($this->managedProxyFragments() as $name => $fragment) {
            $this->assertSame($expectedHashes[$name], hash('sha256', $fragment), $name.' proxy fragment changed');
        }
    }

    public function testManagedProxyFragmentsUseValidLighttpdHttpVariableSyntax(): void
    {
        $fragments = $this->managedProxyFragments();
        foreach ($fragments as $name => $fragment) {
            $this->assertStringContainsString('$HTTP["url"] =~ ', $fragment, $name.' fragment must use lighttpd $HTTP matcher');
            $this->assertStringNotContainsString('\\$HTTP["url"]', $fragment, $name.' fragment must not escape lighttpd $HTTP matcher');
        }

        $rcloneFragment = $fragments['rclone'];
        $this->assertStringContainsString('$REQUEST_HEADER["Content-Length"]', $rcloneFragment);
        $this->assertStringNotContainsString('\\$REQUEST_HEADER["Content-Length"]', $rcloneFragment, 'rclone fragment must not escape lighttpd $REQUEST_HEADER matcher');
    }

    public function testManagedProxyFragmentsEnableWebSocketUpgradeForwarding(): void
    {
        foreach ($this->managedProxyFragments() as $name => $fragment) {
            $this->assertStringContainsAllStrings([
                'proxy.header = (',
                '"upgrade" => "enable"',
            ], $fragment, $name.' proxy fragment must pass WebSocket upgrade requests');
        }
    }

    public function testQbittorrentFragmentRedirectsBasePathWithoutTrailingSlash(): void
    {
        $fragment = \pmssLighttpdManagedProxyFragment('qbittorrent', 'demo', 4002);

        $this->assertStringContainsAllStrings([
            '$HTTP["url"] == "/user-demo/qbittorrent" {',
            'url.redirect = ( "" => "/user-demo/qbittorrent/" )',
            '$HTTP["url"] =~ "^/user-demo/qbittorrent/" {',
            '"/user-demo/qbittorrent/"  => "/"',
        ], $fragment);
        $this->assertStringNotContainsString('"/user-demo/qbittorrent" => ""', $fragment);
        $this->assertStringNotContainsString('$HTTP["url"] =~ "^/user-demo/qbittorrent($|/)" {', $fragment);
    }

    public function testRcloneFragmentAddsZeroContentLengthOnlyForBodylessPosts(): void
    {
        $fragment = \pmssLighttpdManagedProxyFragment('rclone', 'demo', 4001);

        $this->assertStringContainsAllStrings(['$HTTP["request-method"] == "POST" {', '$REQUEST_HEADER["Content-Length"] == "" {', 'setenv.set-request-header = ( "Content-Length" => "0" )'], $fragment);
    }

    public function testOnlyRcloneFragmentTouchesContentLengthHeader(): void
    {
        $fragments = $this->managedProxyFragments();
        $this->assertStringContainsString('Content-Length', $fragments['rclone']);

        foreach (['qbittorrent', 'invidious', 'deluge'] as $proxyName) {
            $this->assertStringNotContainsString(
                'Content-Length',
                $fragments[$proxyName],
                $proxyName.' proxy should not rewrite request bodies'
            );
        }
    }
}
