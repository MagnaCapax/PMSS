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
            $this->assertFalse(strpos($template, $needle) !== false, $message);
        }
    }

    public function testManagedProxyFragmentsMatchCharacterization(): void
    {
        $expectedHashes = [
            'rclone' => 'd386c37104634b335218731ee05713a068944bc6a30e907ee4690dfc6a674c88',
            'qbittorrent' => '251a1a8531807254d4edbc0f097dca364066d59d89d48970eedb73564520c74c',
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
            $this->assertFalse(strpos($fragment, '\\$HTTP["url"]') !== false, $name.' fragment must not escape lighttpd $HTTP matcher');
        }

        $rcloneFragment = $fragments['rclone'];
        $this->assertStringContainsString('$REQUEST_HEADER["Content-Length"]', $rcloneFragment);
        $this->assertFalse(strpos($rcloneFragment, '\\$REQUEST_HEADER["Content-Length"]') !== false, 'rclone fragment must not escape lighttpd $REQUEST_HEADER matcher');
    }

    public function testRcloneFragmentAddsZeroContentLengthOnlyForBodylessPosts(): void
    {
        $fragment = \pmssLighttpdManagedProxyFragment('rclone', 'demo', 4001);

        $this->assertStringContainsString('$HTTP["request-method"] == "POST" {', $fragment);
        $this->assertStringContainsString('$REQUEST_HEADER["Content-Length"] == "" {', $fragment);
        $this->assertStringContainsString('setenv.set-request-header = ( "Content-Length" => "0" )', $fragment);
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
