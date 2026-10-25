<?php
namespace PMSS\Tests;

/** Isolated projections and native observations for Configure render coverage. */
trait TorrentClientsCardsRenderTrait
{
    private function withProjection(?array $entries, callable $callback, array $notInstalled = array()): void
    {
        $this->pmssWithCustomerPanelRender(function ($home, $render, $root) use ($entries, $callback, $notInstalled): void {
            $path = $root.'/torrentClients.json';
            if ($entries !== null) $this->pmssWriteFile($path, json_encode(array('versions' => $entries, 'notInstalled' => $notInstalled)));
            $card = $home.'/www/configureTorrentClientsCard.php';
            $this->pmssWriteFile($card, str_replace('/var/lib/pmss/public/torrentClients.json', $path, file_get_contents($card)));
            // Never rely on or inspect the testing account's actual torrent processes.
            foreach (array('configureTorrentClients.php' => 'pmssConfigureTorrentClientRunningVersion',
                'configureTorrentClientsOther.php' => 'pmssConfigureTorrentClientOtherRunningVersion') as $file => $function) {
                $target = $home.'/www/'.$file;
                $this->pmssWriteFile($target, str_replace('function '.$function.'(', 'function '.$function.'Real(',
                    file_get_contents($target))."\nfunction ".$function."() { return 'system'; }\n");
            }
            $page = function () use ($render): array {
                $html = $render('configure.php', array('minBytes' => 4000))['stdout'];
                preg_match_all('/<section class="configure-card" id="((?:qbittorrent|deluge|transmission|rtorrent)-version|torrent-client-versions)">.*?<\/section>/s', $html, $cards);
                preg_match('/<nav class="configure-index".*?<\/nav>/s', $html, $nav);
                preg_match_all('/href="#((?:qbittorrent|deluge|transmission|rtorrent)-version|torrent-client-versions)"/', $nav[0], $index);
                $this->assertSame($cards[1], $index[1], 'Index matches rendered cards in order');
                $this->assertTrue(strpos($html, 'id="apps"') < strpos($html, $cards[0][0]));
                return array_combine($cards[1], $cards[0]);
            };
            $callback($page, $home, $path);
        });
    }

    private function entries(array $versions): array
    {
        $entries = array();
        foreach ($versions as $client => $names) foreach ($names as $version)
            $entries[] = array('client' => $client, 'version' => $version,
                'kind' => $version === 'system' ? 'system' : ($client === 'deluge' ? 'venv' : 'static'));
        return $entries;
    }
}
