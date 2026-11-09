<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/rtorrent/configDefaults.php';

class RtorrentConfigDefaultsTest extends TestCase
{
    public function testResourcesKeepShallowDefaultsAndStoredKeyOrder(): void
    {
        $path = $this->pmssMakeTempFile('pmss-rtorrent-resources-');
        $defaults = ['ramBlock' => 250, 'peers' => ['minimum' => 6, 'maximum' => 32], 'uploadSlots' => 7];
        foreach ([
            ['{}', $defaults],
            ['[]', $defaults],
            ['{"peers":{"minimum":2}}', ['peers' => ['minimum' => 2], 'ramBlock' => 250, 'uploadSlots' => 7]],
            ['{"ramBlock":null,"uploadSlots":0}', ['ramBlock' => null, 'uploadSlots' => 0, 'peers' => ['minimum' => 6, 'maximum' => 32]]],
            ['["kept"]', [0 => 'kept'] + $defaults],
        ] as [$json, $expected]) {
            $this->pmssWriteFile($path, $json);
            $this->assertSame($expected, \pmssRtorrentConfigResources([], $path));
        }
        // Nonempty injected resources never read the missing host source.
        $missing = $this->pmssMakeTempPath('pmss-rtorrent-missing-');
        $this->assertSame(['custom' => true] + $defaults, \pmssRtorrentConfigResources(['custom' => true], $missing));
    }

    public function testResourcesPreserveMissingAndMalformedSourceErrors(): void
    {
        $missing = $this->pmssMakeTempPath('pmss-rtorrent-missing-');
        $this->assertThrows(\RuntimeException::class, static function () use ($missing): void {
            \pmssRtorrentConfigResources([], $missing);
        }, 'Unable to read rTorrent resource config: '.$missing);
        $path = $this->pmssMakeTempFile('pmss-rtorrent-resources-');
        foreach (['', 'null', 'false', '42', '"text"', '{"broken":'] as $json) {
            $this->pmssWriteFile($path, $json);
            $this->assertThrows(\RuntimeException::class, static function () use ($path): void {
                \pmssRtorrentConfigResources([], $path);
            }, 'Invalid rTorrent resource config JSON in '.$path);
        }
    }

    public function testTemplatePrecedencePreservesBytesAndRejectsOnlyEmptySources(): void
    {
        $root = $this->pmssMakeTempDir('pmss-rtorrent-template-');
        $override = $root.'/override';
        $canonical = $root.'/canonical';
        $this->pmssWriteFile($canonical, "canonical\r\n");
        foreach ([null, '', " \t\r\n", false, 0, []] as $injected) {
            $this->assertSame("canonical\r\n", \pmssRtorrentConfigTemplate($injected, [$override, $canonical]));
        }
        foreach (['0', " injected \n"] as $injected) {
            $this->assertSame($injected, \pmssRtorrentConfigTemplate($injected, []));
        }
        foreach ([" \t\n", '', "override\r\n", '0'] as $contents) {
            $this->pmssWriteFile($override, $contents);
            $expected = trim($contents) === '' ? "canonical\r\n" : $contents;
            $this->assertSame($expected, \pmssRtorrentConfigTemplate(null, [$override, $canonical]));
        }
        $this->assertThrows(\RuntimeException::class, static function () use ($root): void {
            \pmssRtorrentConfigTemplate(null, [$root, $root.'/missing']);
        }, 'Unable to read rTorrent template: /etc/seedbox/config/template.rtorrent.rc');
    }
}
