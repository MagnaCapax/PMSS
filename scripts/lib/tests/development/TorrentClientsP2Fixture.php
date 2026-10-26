<?php
namespace PMSS\Tests;

/** Shared isolated home and fallback-shell fixture. */
trait TorrentClientsP2Fixture
{
    private function fixture(string $name): array
    {
        $root = $this->pmssMakeTempDir('pmss-p2-'.$name.'-');
        putenv('PMSS_HOME_DIR='.$root);
        $home = $root.'/t';
        $this->pmssEnsureDir($home.'/bin/qbittorrent');
        // Copy only account-side helpers; the fallback CLI must restore the fixture home, never the test account.
        $this->pmssEnsureDir($home.'/www');
        $files = ['torrentClientsResolve.php', 'configureFileWrite.php', 'fileReadVerified.php'];
        // Match the delivered dependency closure, including the restored healed helper definitions.
        foreach (['configureTorrentClients', 'qbittorrent', 'scriptsInc'] as $prefix) {
            foreach (glob($this->pmssRepoPath('etc/skel/www/'.$prefix.'*.php')) as $path) $files[] = basename($path);
        }
        foreach ($files as $file) {
            $this->pmssWriteFile($home.'/www/'.$file, $this->pmssReadRepoFile('etc/skel/www/'.$file));
        }
        $this->pmssWriteFile($home.'/.config/qBittorrent/qBittorrent.conf', "[Preferences]\nWebUI\\Port=23456\n");
        $this->pmssEnsureDir($root.'/store/qbittorrent');
        $this->pmssWriteFile($root.'/catalog.json', (string) file_get_contents(dirname(__DIR__, 4).'/etc/seedbox/config/torrentClients.json'));
        $this->pmssWriteFile($root.'/projection.json', '{"versions":[]}');
        $events = (object) ['logs' => []];
        $ops = [
            'homeRoot' => $root, 'root' => $root.'/store',
            'catalog' => $root.'/catalog.json', 'projection' => $root.'/projection.json',
            'run' => $this->pmssAccountPathCommandRunner(),
            'log' => static function (string $user, string $message) use ($events): void {
                $events->logs[] = [$user, $message];
            },
        ];
        return [$root, $home, $ops, $events];
    }

}
