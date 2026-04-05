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
                'expectedHash' => '77bc36ab5fc3da1ee05863ee6f1ee3a7b1fa4c32627ad6c81606ff0648fb0679',
            ],
            'qbittorrent' => [
                'actual' => \pmssQbittorrentLighttpdProxyFragment('demo', 4002),
                'expectedHash' => 'd7b8b21fb36ab1386f6163a207fd3176bfb41a6310ebbefbcc4ed0ad3b7460a9',
            ],
        ];

        foreach ($cases as $name => $case) {
            $this->assertSame($case['expectedHash'], hash('sha256', $case['actual']), $name.' proxy fragment changed');
        }
    }
}
