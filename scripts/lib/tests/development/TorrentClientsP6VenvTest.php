<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/torrentClients/delugeVenv.php';
require_once dirname(__DIR__, 2).'/torrentClients/store.php';

final class TorrentClientsP6VenvTest extends TestCase
{
    private function fixture(): array
    {
        $root = $this->pmssMakeTempDir('pmss-p6-venv-').'/clients';
        mkdir($root.'/wheels', 0755, true);
        mkdir($root.'/deluge', 0755);
        $file = 'deluge-2.1.1-py3-none-any.whl';
        file_put_contents($root.'/wheels/'.$file, 'fixture wheel');
        $entry = ['client' => 'deluge', 'version' => '2.1.1', 'kind' => 'venv',
            'wheels' => [['file' => $file, 'sha256' => hash_file('sha256', $root.'/wheels/'.$file)]]];
        return [$root, $entry];
    }

    public function testMarkerOffAndNoDelugeVersionNeverInvokeInstaller(): void
    {
        [$root, $entry] = $this->fixture();
        $calls = 0;
        $install = static function () use (&$calls): void { $calls++; };
        $marker = $root.'/missing-marker';
        $this->pmssWithEnv(['PMSS_TORRENT_CLIENTS_TEST_MARKER' => $marker], function () use ($root, $entry, $install): void {
            $this->assertFalse(\pmssTorrentClientsDelugeVenvInstall($entry, $root.'/deluge/2.1.1', ['install' => $install]));
        });
        $this->assertSame(0, $calls);
        $results = \pmssTorrentClientsStoreConverge([['client' => 'deluge', 'version' => 'system',
            'kind' => 'system', 'disabled' => false]], ['root' => $root, 'install' => $install]);
        $this->assertSame([], $results);
        $this->assertSame(0, $calls);
    }

    public function testPinnedMirrorUsesExistingInstallerContract(): void
    {
        [$root, $entry] = $this->fixture();
        $calls = [];
        $install = static function ($dir, $label, $steps, $cli, $link) use (&$calls): void {
            $calls = [$dir, $label, $steps, $cli, $link];
            mkdir($dir.'/bin', 0755, true);
            file_put_contents($cli, 'fixture');
            file_put_contents($dir.'/bin/deluge-web', 'fixture');
            symlink($cli, $link);
        };
        $this->assertTrue(\pmssTorrentClientsDelugeVenvInstall($entry, $root.'/deluge/2.1.1', ['install' => $install]));
        $this->assertSame($root.'/deluge/2.1.1', $calls[0]);
        $this->assertSame('Deluge 2.1.1', $calls[1]);
        $this->assertStringContainsString('--no-index --no-deps', $calls[2][0][1]);
        $this->assertStringContainsString($root.'/wheels/'.$entry['wheels'][0]['file'], $calls[2][0][1]);
        $this->assertFalse(is_link($calls[4]));
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/torrentClients/delugeVenv.php');
        $this->assertStringContainsString("'pmssPythonVenvInstallCli'", $source);
        $helper = (string) file_get_contents(dirname(__DIR__, 2).'/update/apps/pythonVenv.php');
        $this->assertStringContainsString('pmssPythonVenvEnsure($venvDir', $helper);
    }

    public function testMissingOrChangedWheelStopsBeforeInstaller(): void
    {
        [$root, $entry] = $this->fixture();
        $calls = 0;
        $install = static function () use (&$calls): void { $calls++; };
        file_put_contents($root.'/wheels/'.$entry['wheels'][0]['file'], 'changed');
        $this->assertFalse(\pmssTorrentClientsDelugeVenvInstall($entry, $root.'/deluge/2.1.1', ['install' => $install]));
        unlink($root.'/wheels/'.$entry['wheels'][0]['file']);
        $this->assertFalse(\pmssTorrentClientsDelugeVenvInstall($entry, $root.'/deluge/2.1.1', ['install' => $install]));
        $this->assertSame(0, $calls);
    }
}
