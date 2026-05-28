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

    public function testManagedProxyFragmentsMatchCharacterization(): void
    {
        $expectedHashes = [
            'rclone' => 'd386c37104634b335218731ee05713a068944bc6a30e907ee4690dfc6a674c88',
            'qbittorrent' => '0d87415eb6f4e749858233f0c74b65f6605d145774d5644c96fbeb84222a4b05',
            'invidious' => '85c16c21a50a20ea8c85dee4fe5d5f1cf8d340f7fd6a44ba534fbacd00e2953c',
            'deluge' => '05b1bc3c5636a341f92e229a4b87118f0917a23a22a53006330c483f0cb9b514',
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

    public function testQbittorrentFragmentMatchesBasePathWithoutTrailingSlash(): void
    {
        $fragment = \pmssLighttpdManagedProxyFragment('qbittorrent', 'demo', 4002);

        $this->assertStringContainsAllStrings(['$HTTP["url"] =~ "^/user-demo/qbittorrent($|/)" {', '"/user-demo/qbittorrent" => ""'], $fragment);
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
