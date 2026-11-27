<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/TorrentClientsCardsRenderTrait.php';

/** Host missing-build metadata and the combined card share the real Configure render path. */
final class TorrentClientsCardsAvailabilityRenderTest extends TestCase
{
    use TorrentClientsCardsRenderTrait;

    /** Password lookups must stay unambiguous when version and custom-settings cards coexist. */
    public function testConfigureIdsAreUniqueAndCustomSettingsReadTheirOwnPassword(): void
    {
        $system = $this->entries(array('qbittorrent' => array('system'), 'deluge' => array('system'),
            'transmission' => array('4.1.3'), 'rtorrent' => array('system')));
        $switchable = $this->entries(array('qbittorrent' => array('system', '5.2.4-lt2.0'),
            'deluge' => array('system', '2.2.0'), 'transmission' => array('4.1.3', '4.1.4'),
            'rtorrent' => array('system', '0.16.25')));
        foreach (array(null, $system, $switchable) as $entries) {
            $this->withProjection($entries, function ($page, $home, $path) use ($entries, $switchable): void {
                // Reuse the isolated projection and process readers, inspecting the entire page here.
                $result = \pmssCustomerPanelRenderPage($home.'/www', dirname($path).'/php-cli-bootstrap.php',
                    dirname($home), $home, 'configure.php', array('minBytes' => 4000));
                $this->assertSame(array(), $result['errors'], implode('; ', $result['errors']));
                $html = $result['stdout'];
                preg_match_all('/\sid="([^"]+)"/', $html, $ids);
                $this->assertTrue(count($ids[1]) > 0, 'Configure renders element IDs');
                $this->assertSame(count($ids[1]), count(array_unique($ids[1])), 'Every rendered ID is unique');
                $this->assertStringContainsString('id="configure-rtorrent-custom-password"', $html);
                if ($entries === $switchable) {
                    // Ensure the regression case actually includes the competing version-card field.
                    $this->assertStringContainsString('id="configure-rtorrent-password"', $html);
                }
                foreach (array('rtorrentCustom', 'rtorrentRestore') as $action) {
                    $this->assertSame(1, preg_match('/onclick="[^"]*pmssConfigureSend\(\''.$action
                        .'\',[^"]*password:document\.getElementById\(\'configure-rtorrent-custom-password\'\)\.value/', $html),
                        $action.' reads the custom-settings password');
                }
            });
        }
    }

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
                $data = $this->pmssReadJsonArrayFile($path);
                unset($data['notInstalled']);
                $this->pmssWriteFile($path, json_encode($data));
                $this->assertSame($cards, $page());
            }, array('qbittorrent' => 0, 'deluge' => 0));
    }

    public function testAllFourReadOnlyClientsUseOneCardAndOneIndexEntry(): void
    {
        $this->withProjection($this->entries(array('qbittorrent' => array('system'), 'deluge' => array('system'),
            'transmission' => array('4.1.3'), 'rtorrent' => array('system'))), function ($page, $home): void {
                $this->pmssWriteSymlinks($home, ['bin/transmission/default' => '/home/.pmss/clients/transmission/4.1.3']);
                $cards = $page();
                $this->assertSame(array('torrent-client-versions'), array_keys($cards));
                $html = $cards['torrent-client-versions'];
                $this->assertStringContainsAndOmitsStrings(array('<p>qBittorrent: system</p>', '<p>Deluge: system</p>',
                    '<p>Transmission: system</p>', '<p>rTorrent: system</p>'),
                    array('<select', 'Switch version', 'not installed'), $html);
            });
    }

    public function testInvalidSelectionNamedSystemStillShowsRecoveryControls(): void
    {
        $this->withProjection($this->entries(array('qbittorrent' => array('system'), 'deluge' => array('system'),
            'rtorrent' => array('system'))), function ($page, $home): void {
                foreach (array('qbittorrent', 'deluge', 'rtorrent') as $client) {
                    $this->pmssWriteSymlinks($home, ['bin/'.$client.'/default' => 'system']);
                }
                $cards = $page();
                $this->assertSame(array('qbittorrent-version', 'deluge-version', 'rtorrent-version'), array_keys($cards));
                foreach ($cards as $html) $this->assertStringContainsAllStrings(array('<select', '<input type="password"', 'Switch version'), $html);
            });
    }
}
