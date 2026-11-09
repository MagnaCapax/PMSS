<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/rtorrentPortReservationsReconcile.php';
require_once dirname(__DIR__, 2).'/rtorrent/portSources.php';

class RtorrentPortSourcesCharacterizationTest extends TestCase
{
    public function testPayloadDecodingKeepsAbsentAndMalformedOwnershipDistinct(): void
    {
        foreach ([
            [[], ['ports' => [], 'uncertain' => []]],
            [['rtorrentDhtPort' => 24001], ['ports' => [], 'uncertain' => []]],
            [['rtorrentPort' => 4000], ['ports' => ['scgi' => [4000 => true]], 'uncertain' => []]],
            [['rtorrentPort' => '24000'], ['ports' => ['scgi' => [24000 => true]], 'uncertain' => []]],
            [['rtorrentPort' => '004001'], ['ports' => ['scgi' => [4001 => true]], 'uncertain' => []]],
        ] as [$payload, $expected]) {
            $this->assertSame($expected, \pmssRtorrentPortReservationPayloadSource($payload));
        }
        foreach ([null, false, [], 'bad', 3999, 24001, '4001x', "4001\0"] as $value) {
            $this->assertSame(['ports' => [], 'uncertain' => ['scgi' => true]],
                \pmssRtorrentPortReservationPayloadSource(['rtorrentPort' => $value]));
        }
    }

    public function testConfigDecoderPreservesReferencesOrderCommentsAndUncertainty(): void
    {
        foreach ([
            [[], ['ports' => [], 'uncertain' => []]],
            [["# scgi_port = 4001", " / scgi_port = 4002", 'dht.port.set = 44000'], ['ports' => [], 'uncertain' => []]],
            [['scgi_port = 4002', 'network.scgi.open_port = 127.0.0.1:4001', 'SCGI_PORT=4002 # repeat'],
                ['ports' => ['scgi' => [4002 => true, 4001 => true]], 'uncertain' => []]],
            [['scgi_port = bad', 'scgi_port = 4003', 'scgi_port = 24001'],
                ['ports' => ['scgi' => [4003 => true]], 'uncertain' => ['scgi' => true]]],
            [['scgi_port =', 'scgi_port = # absent', 'unrelated = 4001'], ['ports' => [], 'uncertain' => []]],
            [['scgi_port = 4001=extra'], ['ports' => [], 'uncertain' => ['scgi' => true]]],
        ] as [$lines, $expected]) {
            $this->assertSame($expected, \pmssRtorrentPortReservationConfigParse($lines));
        }
    }

    public function testConfigAcquisitionRetainsMissingAndLinkedSourceRefusals(): void
    {
        $root = $this->pmssMakeTempDir('pmss-rtorrent-sources-');
        $path = $root.'/config';
        $this->assertSame(['ports' => [], 'uncertain' => ['scgi' => true]], \pmssRtorrentPortReservationConfigSource($path));
        $this->pmssWriteFile($path, "# retained\nscgi_port = 4001\r\nscgi_port = bad\n");
        $this->assertSame(['ports' => ['scgi' => [4001 => true]], 'uncertain' => ['scgi' => true]],
            \pmssRtorrentPortReservationConfigSource($path));
        $link = $root.'/link';
        $this->pmssCreateSymlinkOrSkip($path, $link);
        $this->assertSame(['ports' => [], 'uncertain' => ['scgi' => true]], \pmssRtorrentPortReservationConfigSource($link));
    }

    public function testReconciliationUnionsStoredAndConfiguredOwnershipBeforeRemoval(): void
    {
        $root = $this->pmssMakeTempDir('pmss-rtorrent-reconcile-sources-');
        $home = $root.'/home';
        $config = $root.'/config';
        $ports = $root.'/ports';
        $this->pmssWriteFiles($root, [
            'config/users/fixtur01.json' => '{"rtorrentPort":4001}',
            'config/users/fixtur02.json' => '{"rtorrentPort":4002}',
            'home/fixtur01/.rtorrent.rc' => "scgi_port = 4002\n",
            'home/fixtur02/.rtorrent.rc' => "scgi_port = 4003\n",
            'ports/scgi/4001' => '', 'ports/scgi/4002' => '', 'ports/scgi/4003' => '', 'ports/scgi/4004' => '',
        ]);
        $result = \pmssRtorrentPortReservationsReconcile(['fixtur01', 'fixtur02'], $home, $config, $ports,
            time() + 3600, 0, $root.'/reconcile.lock');
        $this->assertSame(['status' => 'ok', 'reason' => '', 'removed' => 1, 'kept' => 3, 'errors' => 0], $result);
        foreach ([4001, 4002, 4003] as $port) $this->assertTrue(is_file($ports.'/scgi/'.$port));
        $this->assertFalse(file_exists($ports.'/scgi/4004'));
    }
}
