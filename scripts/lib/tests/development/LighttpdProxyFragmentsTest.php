<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/util/userConfigLighttpd.php';

class LighttpdProxyFragmentsTest extends TestCase
{
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
        $cases = [
            'rclone' => [
                'actual' => \pmssLighttpdManagedProxyFragment('rclone', 'demo', 4001),
                'expectedHash' => 'd386c37104634b335218731ee05713a068944bc6a30e907ee4690dfc6a674c88',
            ],
            'qbittorrent' => [
                'actual' => \pmssLighttpdManagedProxyFragment('qbittorrent', 'demo', 4002),
                'expectedHash' => '251a1a8531807254d4edbc0f097dca364066d59d89d48970eedb73564520c74c',
            ],
            'invidious' => [
                'actual' => \pmssLighttpdManagedProxyFragment('invidious', 'demo', 4003),
                'expectedHash' => '85c16c21a50a20ea8c85dee4fe5d5f1cf8d340f7fd6a44ba534fbacd00e2953c',
            ],
            'deluge' => [
                'actual' => \pmssLighttpdManagedProxyFragment('deluge', 'demo', 4004),
                'expectedHash' => '05b1bc3c5636a341f92e229a4b87118f0917a23a22a53006330c483f0cb9b514',
            ],
        ];

        foreach ($cases as $name => $case) {
            $this->assertSame($case['expectedHash'], hash('sha256', $case['actual']), $name.' proxy fragment changed');
        }
    }

    public function testManagedProxyFragmentsUseValidLighttpdHttpVariableSyntax(): void
    {
        $cases = [
            'rclone' => \pmssLighttpdManagedProxyFragment('rclone', 'demo', 4001),
            'qbittorrent' => \pmssLighttpdManagedProxyFragment('qbittorrent', 'demo', 4002),
            'invidious' => \pmssLighttpdManagedProxyFragment('invidious', 'demo', 4003),
            'deluge' => \pmssLighttpdManagedProxyFragment('deluge', 'demo', 4004),
        ];

        foreach ($cases as $name => $fragment) {
            $this->assertStringContainsString('$HTTP["url"] =~ ', $fragment, $name.' fragment must use lighttpd $HTTP matcher');
            $this->assertFalse(strpos($fragment, '\\$HTTP["url"]') !== false, $name.' fragment must not escape lighttpd $HTTP matcher');
        }

        $rcloneFragment = $cases['rclone'];
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
        $this->assertStringContainsString('Content-Length', \pmssLighttpdManagedProxyFragment('rclone', 'demo', 4001));

        foreach (['qbittorrent', 'invidious', 'deluge'] as $proxyName) {
            $this->assertStringNotContainsString(
                'Content-Length',
                \pmssLighttpdManagedProxyFragment($proxyName, 'demo', 4001),
                $proxyName.' proxy should not rewrite request bodies'
            );
        }
    }
}
