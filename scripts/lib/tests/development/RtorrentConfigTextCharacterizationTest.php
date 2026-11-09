<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/rtorrentConfig.php';
require_once dirname(__DIR__, 2).'/rtorrent/configText.php';

class RtorrentConfigTextCharacterizationTest extends TestCase
{
    public function testParserPreservesLegacyFilteringAndDuplicateOrder(): void
    {
        foreach ([
            ['', []],
            [" \t\n# comment\n / ignored = yes\nmalformed\n", []],
            ["a = first\nb = middle\na = last\n", ['a' => 'last', 'b' => 'middle']],
            [" = empty key\nempty = \n0 = numeric\n", ['' => 'empty key', 'empty' => '', 0 => 'numeric']],
            ["extra = ignored = value\ngood = kept # inline\n", ['good' => 'kept # inline']],
            ["a = one\r\nb = two\r\n", ['a' => 'one', 'b' => 'two']],
            ["a = one\rb = two", []],
            ["a = one\rb", ['a' => "one\rb"]],
        ] as [$text, $expected]) {
            $this->assertSame($expected, \pmssRtorrentConfigParse($text));
        }
    }

    public function testLocalnetRepairRetainsExactSuffixAndFramingRules(): void
    {
        $legacy = 'ipv4_filter.load = /etc/seedbox/config/localnet, preferred';
        $wrapped = 'catch = {"ipv4_filter.load=/etc/seedbox/config/localnet,preferred"}';
        foreach ([
            ['', ''],
            [$legacy, $legacy],
            [$legacy."\n", $legacy."\n"],
            ["\n".$legacy, "\n".$wrapped],
            ["x = y\n".$legacy."\n\n\n", "x = y\n".$wrapped."\n\n\n"],
            ["x = y\r\n".$legacy, "x = y\r\n".$wrapped],
            ["x = y\n".$legacy."\r\n", "x = y\n".$legacy."\r\n"],
            ["x = y\n".$legacy." \n", "x = y\n".$legacy." \n"],
            ["x = y\n".$legacy."\n# keep", "x = y\n".$legacy."\n# keep"],
            ["\n".$legacy."\n".$legacy, "\n".$legacy."\n".$wrapped],
        ] as [$text, $expected]) {
            $this->assertSame($expected, \pmssRtorrentConfigLocalnetReplace($text));
            $this->assertSame($expected, \pmssRtorrentConfigLocalnetReplace($expected));
        }
    }

    public function testEncodingRepairPreservesPhysicalLinesAndEditedValues(): void
    {
        $guard = 'branch = ((equal, ((system.client_version)), ((cat, "0.9.8")))), '
            .'((encoding.add, utf8)), ((network.bind_address.set, "0.0.0.0"))';
        foreach ([
            ['encoding.add = utf8', $guard],
            ["encoding.add = utf8\r\nencoding.add = utf8\n", $guard."\r\n".$guard."\n"],
            ["# keep\nencoding.add = utf8\ncustom = retained", "# keep\n".$guard."\ncustom = retained"],
            ['', ''],
            [" encoding.add = utf8\n", " encoding.add = utf8\n"],
            ["encoding.add = utf8 # edited\n", "encoding.add = utf8 # edited\n"],
            ["encoding.add = utf8\rx = y", "encoding.add = utf8\rx = y"],
            ["encoding.add = utf8\r", $guard."\r"],
        ] as [$text, $expected]) {
            $this->assertSame($expected, \pmssRtorrentConfigEncodingReplace($text));
            $this->assertSame($expected, \pmssRtorrentConfigEncodingReplace($expected));
        }
    }

    public function testFacadeKeepsReadFailuresNoOpsAndWriterResults(): void
    {
        $root = $this->pmssMakeTrackedHomeRoot('pmss-rtorrent-text-');
        $home = $this->pmssEnsureDir($this->pmssUserHomePath($root, 'fixtur01'));
        $path = $home.'/.rtorrent.rc';
        $config = new class(['custom' => true], 'unused') extends \rtorrentConfig {
            public $writes = [];
            public function writeConfig($user, $text) { $this->writes[] = [$user, $text]; return false; }
        };
        foreach (['migrateLegacyLocalnetLine', 'migrateLegacyEncodingLine'] as $method) {
            $this->assertSame(null, $config->$method('fixtur01'));
            $this->assertThrows(\InvalidArgumentException::class, static function () use ($config, $method): void {
                $config->$method('../outside');
            }, 'valid PMSS username');
        }
        $this->assertSame(false, $config->readUserConfig('fixtur01'));
        foreach (['', "# comment\n", "a = b\n"] as $text) {
            $this->pmssWriteFile($path, $text);
            $this->assertSame($text === '' ? false : ($text === "a = b\n" ? ['a' => 'b'] : []), $config->readConfig($path));
            $this->assertSame(null, $config->migrateLegacyLocalnetLine('fixtur01'));
            $this->assertSame(null, $config->migrateLegacyEncodingLine('fixtur01'));
        }
        $this->assertSame([], $config->writes);
        foreach ([
            ['migrateLegacyLocalnetLine', "x = y\nipv4_filter.load = /etc/seedbox/config/localnet, preferred"],
            ['migrateLegacyEncodingLine', 'encoding.add = utf8'],
        ] as [$method, $text]) {
            $this->pmssWriteFile($path, $text);
            $this->assertSame(false, $config->$method('fixtur01'));
        }
        $this->assertSame(2, count($config->writes));
        $this->assertSame('fixtur01', $config->writes[0][0]);
        $this->assertSame('fixtur01', $config->writes[1][0]);
    }
}
