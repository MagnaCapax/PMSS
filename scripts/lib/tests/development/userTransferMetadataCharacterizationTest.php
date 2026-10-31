<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/userTransfer.php';

/** Freeze transfer metadata bytes and legacy parser boundaries without running probes. */
class UserTransferMetadataCharacterizationTest extends TestCase
{
    public function testBencodeBytesAndPartialReplacementCounts(): void
    {
        $source = '/home/fixtur01/data';
        $target = '/home/fixtur2/data';
        $string = static function (string $value): string { return strlen($value).':'.$value; };
        foreach ([
            ['', '', 0], ['d', 'd', 0], ['iXe', 'iXe', 0], ['i12', null, 0],
            ['0003:abc', '3:abc', 0], ['000000000:', '0:', 0], ['0000000000:', null, 0],
            ['12', null, 0], ['5:abc', null, 0], [':', null, 0],
            [$string('/home/fixtur01'), $string('/home/fixtur01'), 0],
            [$string($source), $string($target), 1],
            [$string($source.' '.$source), $string($target.' '.$target), 2],
            ['d3:key'.$string($source).'e', 'd3:key'.$string($target).'e', 1],
            [$string($source).'X', null, 1],
            [$string("\0".$source), $string("\0".$target), 1],
        ] as [$payload, $expected, $count]) {
            $actualCount = 99;
            $actual = \pmssUserTransferRewriteBencodedHomePaths($payload, 'fixtur01', 'fixtur2', $actualCount);
            $this->assertSame([$expected, $count], [$actual, $actualCount], bin2hex($payload));
        }
        // Identical account names bypass parsing, including malformed input.
        $count = 99;
        $this->assertSame('malformed', \pmssUserTransferRewriteBencodedHomePaths('malformed', 'fixtur01', 'fixtur01', $count));
        $this->assertSame(0, $count);
    }

    public function testQtSettingsEscapeBytes(): void
    {
        foreach ([
            ['', ''], ['plain', 'plain'], ['\\0\\n\\r\\t', "\0\n\r\t"],
            ['\\x414', 'A4'], ['\\x4g', "\x04g"], ['\\x', 'x'], ['\\xzz', 'xzz'],
            ['\\X41', 'X41'], ['\\z', 'z'], ['end\\', 'end\\'], ['\\\\', '\\'],
        ] as [$encoded, $expected]) {
            $this->assertSame($expected, \pmssUserTransferQtSettingsBytesDecode($encoded), $encoded);
        }
    }

    public function testQtStringResultsAndConsumedOffsets(): void
    {
        foreach ([
            ['', null, 0], ["\0\0\0", null, 0],
            [pack('N', 0), '', 4], [pack('N', 2)."\0A", 'A', 6],
            [pack('N', 4)."\0A\0Btail", 'AB', 8],
            [pack('N', 1).'A', null, 4], [pack('N', 4)."\0A", null, 4],
            [pack('N', 0xffffffff), null, 4], [pack('N', 0xfffffffe), null, 4],
        ] as [$bytes, $expected, $consumed]) {
            $offset = 0;
            $actual = \pmssUserTransferQtReadString($bytes, $offset);
            $this->assertSame([$expected, $consumed], [$actual, $offset], bin2hex($bytes));
        }
    }

    public function testCategoryPathAndOptionProjection(): void
    {
        $cfg = ['remoteUser' => 'fixtur01', 'localUser' => 'fixtur2'];
        foreach ([
            ['', ''], ['/home/fixtur01', '/home/fixtur2'],
            ['/home/fixtur01/data', '/home/fixtur2/data'],
            ['/home/fixtur01/', '/home/fixtur2/'],
            ['/home/fixtur010/data', '/home/fixtur010/data'],
            ['/other/home/fixtur01/data', '/other/home/fixtur01/data'],
        ] as [$path, $expected]) {
            $this->assertSame($expected, \pmssUserTransferQbittorrentCategoryPathRewrite($path, $cfg));
        }
        $this->assertSame('/home/fixtur01/data', \pmssUserTransferQbittorrentCategoryPathRewrite('/home/fixtur01/data', []));
        foreach ([
            [null, ['save_path' => '']], [false, ['save_path' => '']],
            [['extra' => 1, 'savePath' => '/data'], ['extra' => 1, 'save_path' => '/data']],
            [['savePath' => '/old', 'save_path' => '/keep'], ['savePath' => '/old', 'save_path' => '/keep']],
            [['savePath' => 4], ['save_path' => '']],
        ] as [$options, $expected]) {
            $this->assertSame($expected, \pmssUserTransferQbittorrentCategoryOptionsNormalize($options));
        }
    }

    public function testAggregatePrecedenceAndCompletenessBoundary(): void
    {
        foreach ([
            ["12 legacy\nbytes=34 files=5", 34, ['bytes' => 34, 'files' => 5]],
            ['bytes=00034 files=005', 34, ['bytes' => 34, 'files' => 5]],
            ['bytes=34 files=5x', 34, null], ['bytes=-1 files=5', null, null],
            ['bytes='.PHP_INT_MAX.'0 files=5', null, null],
        ] as [$output, $bytes, $stats]) {
            $this->assertSame($bytes, \pmssUserTransferParseDuBytes($output));
            $this->assertSame($stats, \pmssUserTransferParseHomeStats($output));
        }
        foreach ([[0, 0, 90], [-1, 0, 90], [100, 90, 90], [100, 100, 90], [100, 0, 0]] as [$remote, $local, $threshold]) {
            $this->assertSame(null, \pmssUserTransferEvaluateCompleteness($remote, $local, $threshold));
        }
        $this->assertSame(['remoteBytes' => 100, 'localBytes' => 89, 'verifyThreshold' => 90, 'localPercent' => 89.0],
            \pmssUserTransferEvaluateCompleteness(100, 89, 90));
    }

    public function testCategoryProbeScriptBytes(): void
    {
        $prefix = "ssh -F /dev/null -o Compression=no -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no"
            ." -o ConnectTimeout=20 -o NumberOfPasswordPrompts=1 -l 'fixtur01' 'source.example' ";
        $config = "if [ -r '/home/fixtur01/.config/qBittorrent/qBittorrent.conf' ]; then cat '/home/fixtur01/.config/qBittorrent/qBittorrent.conf'; fi";
        $categories = "if [ -r '/home/fixtur01/.config/qBittorrent/categories.json' ]; then cat '/home/fixtur01/.config/qBittorrent/categories.json'; fi";
        $expected = "#!/bin/bash\nset -e\numask 077\n"
            .$prefix.escapeshellarg($config)." > '/scratch/config file'\n"
            .$prefix.escapeshellarg($categories)." > '/scratch/categories'\n";
        $this->assertSame($expected, \pmssUserTransferBuildQbittorrentCategoryProbe(
            ['remoteUser' => 'fixtur01', 'hostname' => 'source.example'], '/scratch/config file', '/scratch/categories'));
    }
}
