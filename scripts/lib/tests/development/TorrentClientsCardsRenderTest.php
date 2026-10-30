<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/TorrentClientsCardsRenderTrait.php';

/** Render the real Configure page in the shared harness; only projection and native observations are fixtures. */
final class TorrentClientsCardsRenderTest extends TestCase
{
    use TorrentClientsCardsRenderTrait;

    public function testMultipleVersionsKeepLegacyMarkupForAllFourClients(): void
    {
        $versions = array('qbittorrent' => array('system', '5.1.4-lt1.2', '5.1.4-lt2.0'),
            'deluge' => array('system', '2.2.0'), 'transmission' => array('4.0.5', '4.1.3'),
            'rtorrent' => array('system', '0.16.25'));
        $this->withProjection($this->entries($versions), function ($page) use ($versions): void {
            $cards = $page();
            $this->assertSame(array('qbittorrent-version', 'deluge-version', 'transmission-version', 'rtorrent-version'), array_keys($cards));
            foreach (array('qbittorrent' => 'qBittorrent', 'deluge' => 'Deluge',
                'transmission' => 'Transmission', 'rtorrent' => 'rTorrent') as $client => $label) {
                // Frozen pre-refactor markup, including the exact action and client-specific confirmation.
                $options = '';
                foreach ($versions[$client] as $version) {
                    $shown = str_replace(array('-lt1.2', '-lt2.0'), array(' (libtorrent 1.2)', ' (libtorrent 2.0)'), $version);
                    $options .= '<option value="'.$version.'"'.($version === 'system' ? ' selected' : '').'>'.$shown.'</option>';
                }
                $confirm = $client === 'transmission'
                    ? 'Restart Transmission with this version? Its settings are not part of the configuration backup.'
                    : 'Back up the current configuration and restart '.$label.' with this version?';
                $expected = '<section class="configure-card" id="'.$client.'-version"><h2>'.$label.' version</h2><p>Running: system</p>'
                    .'<div class="configure-actions"><select id="configure-'.$client.'-version" aria-label="'.$label.' version">'.$options.'</select>'
                    .'<input type="password" id="configure-'.$client.'-password" autocomplete="current-password" placeholder="Account password" aria-label="Account password"><button type="button" onclick="pmssConfigureSend(\'torrentClientSwitch\',{client:\''.$client.'\',version:document.getElementById(\'configure-'.$client.'-version\').value,password:document.getElementById(\'configure-'.$client.'-password\').value},\''.$confirm.'\')">Switch version</button></div></section>';
                $this->assertSame($expected, str_replace("\n", '', $cards[$client.'-version']));
            }
        });
    }

    public function testSystemOnlyCardsAreReadOnlyAndNameMissingTransmissionOnce(): void
    {
        $this->withProjection($this->entries(array('qbittorrent' => array('system'), 'deluge' => array('system'),
            'rtorrent' => array('system'))), function ($page, $home): void {
            $dir = $this->pmssEnsureDir($home.'/.config/pmss/torrentClients');
            $this->pmssWriteFile($dir.'/qbittorrent.notice', "Kept <notice> & status\n");
            $cards = $page();
            $this->assertSame(array('torrent-client-versions'), array_keys($cards));
            foreach ($cards as $card) {
                $this->assertStringContainsAndOmitsStrings(array('qBittorrent: system', 'Deluge: system', 'rTorrent: system',
                    'Other versions are not installed on this server. This depends on the server, not on your account.'),
                    array('<select', '<input type="password"', 'Switch version'), $card);
            }
            $this->assertStringContainsAllStrings(['<p role="status">Kept &lt;notice&gt; &amp; status</p>',
                'Transmission is not installed on this server.'], $cards['torrent-client-versions']);
            $this->assertSame(1, substr_count(implode('', $cards), 'Transmission is not installed'));
            $this->assertSame(1, substr_count(implode('', $cards), 'Other versions are not installed'));
        }, array('qbittorrent' => 2, 'deluge' => 1, 'rtorrent' => 1, 'transmission' => 2));
    }

    public function testOneOfferedVersionKeepsControlsForCustomOrWithdrawnSelection(): void
    {
        $this->withProjection($this->entries(array('qbittorrent' => array('system'), 'rtorrent' => array('system'))),
            function ($page, $home): void {
                foreach (array('qbittorrent' => 'custom-build', 'rtorrent' => 'withdrawn-build') as $client => $version) {
                    $this->pmssEnsureDir($home.'/bin/'.$client);
                    $target = $client === 'rtorrent' ? '/home/.pmss/clients/'.$client.'/'.$version : $home.'/bin/'.$client.'/'.$version;
                    $this->pmssCreateSymlinkOrSkip($target, $home.'/bin/'.$client.'/default');
                }
                $cards = $page();
                foreach ($cards as $card) $this->assertStringContainsAllStrings(array('<select', '<input type="password"',
                    'Switch version', 'Other versions are not installed on this server.'), $card);
                $this->assertStringContainsString('Deluge and Transmission are not installed on this server.', $cards['qbittorrent-version']);
            }, array('qbittorrent' => 1, 'rtorrent' => 1));
    }

    public function testMissingInvalidLinkedOversizedAndUnreadableProjectionsShowOneUnavailableCard(): void
    {
        $this->withProjection(null, function ($page, $home, $path): void {
            $assertUnavailable = function () use ($page): void {
                $cards = $page();
                $this->assertSame(array('torrent-client-versions'), array_keys($cards));
                $this->assertSame('<section class="configure-card" id="torrent-client-versions"><h2>Torrent client versions</h2><p>Version choice is not available on this server.</p></section>', $cards['torrent-client-versions']);
            };
            $assertUnavailable();
            foreach (array('{', 'null', '{"versions":null}', str_repeat(' ', 65537)) as $raw) {
                $this->pmssWriteFile($path, $raw);
                $assertUnavailable();
            }
            if (posix_geteuid() !== 0) {
                $this->pmssWriteFile($path, '{"versions":[]}');
                $this->assertTrue(chmod($path, 0000));
                try { $assertUnavailable(); } finally { chmod($path, 0600); }
            }
            $this->assertTrue(unlink($path));
            $target = $this->pmssWriteFile($home.'/valid.json', '{"versions":[]}');
            $this->pmssCreateSymlinkOrSkip($target, $path);
            $assertUnavailable();
        });
    }

    public function testValidEmptyProjectionShowsAllClientsAbsentInOneCard(): void
    {
        $this->withProjection(array(), function ($page): void {
            $cards = $page();
            $this->assertSame(array('torrent-client-versions'), array_keys($cards));
            $this->assertStringContainsAndOmitsStrings(['qBittorrent, Deluge, Transmission and rTorrent are not installed on this server.'],
                ['Version choice is not available'], $cards['torrent-client-versions']);
        });
    }
}
