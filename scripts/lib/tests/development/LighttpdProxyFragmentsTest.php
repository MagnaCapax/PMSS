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
                'actual' => \pmssRcloneLighttpdProxyFragment('demo', 4001),
                'expectedHash' => '7c0160200a8bc640c0571ab0b6cea03ab31c36b15b8289a1881652a2e47ccf77',
            ],
            'qbittorrent' => [
                'actual' => \pmssQbittorrentLighttpdProxyFragment('demo', 4002),
                'expectedHash' => '251a1a8531807254d4edbc0f097dca364066d59d89d48970eedb73564520c74c',
            ],
        ];

        foreach ($cases as $name => $case) {
            $this->assertSame($case['expectedHash'], hash('sha256', $case['actual']), $name.' proxy fragment changed');
        }
    }

    public function testManagedProxyFragmentsUseValidLighttpdHttpVariableSyntax(): void
    {
        $cases = [
            'rclone' => \pmssRcloneLighttpdProxyFragment('demo', 4001),
            'qbittorrent' => \pmssQbittorrentLighttpdProxyFragment('demo', 4002),
            'invidious' => \pmssInvidiousLighttpdProxyFragment('demo', 4003),
            'deluge' => \pmssDelugeLighttpdProxyFragment('demo', 4004),
        ];

        foreach ($cases as $name => $fragment) {
            $this->assertStringContainsString('$HTTP["url"] =~ ', $fragment, $name.' fragment must use lighttpd $HTTP matcher');
            $this->assertFalse(strpos($fragment, '\\$HTTP["url"]') !== false, $name.' fragment must not escape lighttpd $HTTP matcher');
        }
    }
}
