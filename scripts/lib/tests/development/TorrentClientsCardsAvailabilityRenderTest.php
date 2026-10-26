<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/TorrentClientsCardsRenderTrait.php';

/** Host missing-build metadata and the combined card share the real Configure render path. */
final class TorrentClientsCardsAvailabilityRenderTest extends TestCase
{
    use TorrentClientsCardsRenderTrait;

    public function testDeliveredConfigureTreeRendersWithOneUnmodifiedVersionReader(): void
    {
        $this->pmssWithCustomerPanelRender(function ($home, $render, $root): void {
            $source = '';
            $manifest = $this->pmssReadRepoFile('scripts/lib/update/users/filesystem.php');
            foreach (['configureTorrentClients.php', 'configureTorrentClientsProcess.php', 'configureTorrentClientsStart.php'] as $file) {
                $this->assertStringContainsString("'www/".$file."'", $manifest);
                $expected = $this->pmssReadRepoFile('etc/skel/www/'.$file);
                $this->assertSame($expected, file_get_contents($home.'/www/'.$file));
                $source .= $expected;
            }
            $this->assertSame(1, substr_count($source, 'function pmssConfigureTorrentClientRunningVersion('));
            // Isolate only availability; keep every delivered function definition intact.
            $card = $home.'/www/configureTorrentClientsCard.php';
            $this->pmssWriteFile($card, str_replace('/var/lib/pmss/public/torrentClients.json',
                $root.'/missing-projection.json', file_get_contents($card)));
            $html = $render('configure.php', ['minBytes' => 4000])['stdout'];
            $this->assertStringContainsString('Version choice is not available on this server.', $html);
        });
    }

    public function testMissingBuildSentenceUsesCountsForBothSingleAndMultipleOptions(): void
    {
        $this->withProjection($this->entries(array('qbittorrent' => array('system', '5.2.4-lt2.0'),
            'deluge' => array('system'), 'rtorrent' => array('system'))), function ($page): void {
                $cards = $page();
                $this->assertSame(array('qbittorrent-version', 'deluge-version', 'rtorrent-version'), array_keys($cards));
                foreach (array('qbittorrent', 'rtorrent') as $client)
                    $this->assertStringContainsString('This depends on the server, not on your account.', $cards[$client.'-version']);
                $this->assertStringNotContainsString('Other versions', $cards['deluge-version']);
                $this->assertStringNotContainsString('<select', $cards['rtorrent-version']);
                $this->assertStringContainsString('<select', $cards['qbittorrent-version']);
                $this->assertSame(1, substr_count(implode('', $cards), 'Transmission is not installed on this server.'));
            }, array('qbittorrent' => 3, 'rtorrent' => 1, 'transmission' => 2));
    }

    public function testNoMissingBuildSentenceForZeroOrAbsentCountsIncludingSingleOptions(): void
    {
        $this->withProjection($this->entries(array('qbittorrent' => array('system'), 'deluge' => array('system'))),
            function ($page, $home, $path): void {
                $cards = $page();
                $this->assertSame(array('torrent-client-versions'), array_keys($cards));
                $this->assertStringNotContainsString('Other versions', implode('', $cards));
                // Also exercise an older projection with no notInstalled map at all.
                $data = json_decode(file_get_contents($path), true);
                unset($data['notInstalled']);
                $this->pmssWriteFile($path, json_encode($data));
                $this->assertSame($cards, $page());
            }, array('qbittorrent' => 0, 'deluge' => 0));
    }

    public function testAllFourReadOnlyClientsUseOneCardAndOneIndexEntry(): void
    {
        $this->withProjection($this->entries(array('qbittorrent' => array('system'), 'deluge' => array('system'),
            'transmission' => array('4.1.3'), 'rtorrent' => array('system'))), function ($page, $home): void {
                $this->pmssEnsureDir($home.'/bin/transmission');
                $this->pmssCreateSymlinkOrSkip('/home/.pmss/clients/transmission/4.1.3', $home.'/bin/transmission/default');
                $cards = $page();
                $this->assertSame(array('torrent-client-versions'), array_keys($cards));
                $html = $cards['torrent-client-versions'];
                foreach (array('qBittorrent', 'Deluge', 'Transmission', 'rTorrent') as $label)
                    $this->assertStringContainsString('<p>'.$label.': system</p>', $html);
                foreach (array('<select', 'Switch version', 'not installed') as $text)
                    $this->assertStringNotContainsString($text, $html);
            });
    }

    public function testInvalidSelectionNamedSystemStillShowsRecoveryControls(): void
    {
        $this->withProjection($this->entries(array('qbittorrent' => array('system'), 'deluge' => array('system'),
            'rtorrent' => array('system'))), function ($page, $home): void {
                foreach (array('qbittorrent', 'deluge', 'rtorrent') as $client) {
                    $this->pmssEnsureDir($home.'/bin/'.$client);
                    $this->pmssCreateSymlinkOrSkip('system', $home.'/bin/'.$client.'/default');
                }
                $cards = $page();
                $this->assertSame(array('qbittorrent-version', 'deluge-version', 'rtorrent-version'), array_keys($cards));
                foreach ($cards as $html) $this->assertStringContainsAllStrings(array('<select', '<input type="password"', 'Switch version'), $html);
            });
    }
}
