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

    public function testSystemDelugeNeverInvokesStoreInstaller(): void
    {
        [$root, $entry] = $this->fixture();
        $calls = 0;
        $install = static function () use (&$calls): void { $calls++; };
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
        $fetched = [];
        $fetch = static function (string $file) use (&$fetched): void { $fetched[] = $file; };
        $ops = ['install' => $install, 'fetch' => $fetch];
        file_put_contents($root.'/wheels/'.$entry['wheels'][0]['file'], 'changed');
        $this->assertFalse(\pmssTorrentClientsDelugeVenvInstall($entry, $root.'/deluge/2.1.1', $ops));
        unlink($root.'/wheels/'.$entry['wheels'][0]['file']);
        $this->assertFalse(\pmssTorrentClientsDelugeVenvInstall($entry, $root.'/deluge/2.1.1', $ops));
        $this->assertSame(0, $calls);
        // A failed mirror fetch is retried on the next run and never reaches pip.
        $this->assertSame([$entry['wheels'][0]['file'], $entry['wheels'][0]['file']], $fetched);
    }

    public function testMirrorFetchFillsMissingWheelBeforeHashCheck(): void
    {
        [$root, $entry] = $this->fixture();
        $file = $entry['wheels'][0]['file'];
        unlink($root.'/wheels/'.$file);
        $args = [];
        $fetch = static function (string $name, string $sha256, string $path) use (&$args): void {
            $args[] = [$name, $sha256, $path];
            file_put_contents($path, 'fixture wheel');
        };
        $install = static function ($dir, $label, $steps, $cli, $link): void {
            mkdir($dir.'/bin', 0755, true);
            file_put_contents($cli, 'fixture');
            file_put_contents($dir.'/bin/deluge-web', 'fixture');
            symlink($cli, $link);
        };
        $this->assertTrue(\pmssTorrentClientsDelugeVenvInstall($entry, $root.'/deluge/2.1.1',
            ['install' => $install, 'fetch' => $fetch]));
        $this->assertSame([[$file, $entry['wheels'][0]['sha256'], $root.'/wheels/'.$file]], $args);
    }

    public function testFailedTemporaryLinkCleanupPreventsPublication(): void
    {
        [$root, $entry] = $this->fixture();
        $versionDir = $root.'/deluge/2.1.1';
        $install = static function ($dir, $label, $steps, $cli, $link): void {
            mkdir($dir.'/bin', 0755, true);
            file_put_contents($cli, 'fixture');
            file_put_contents($dir.'/bin/deluge-web', 'fixture');
            symlink($cli, $link);
        };
        $cleanupCalls = 0;
        $unlink = static function (string $path) use (&$cleanupCalls): bool {
            $cleanupCalls++;
            return false;
        };
        $this->assertFalse(\pmssTorrentClientsDelugeVenvInstall($entry, $versionDir,
            ['install' => $install, 'unlink' => $unlink]));
        $this->assertSame(1, $cleanupCalls);
        $this->assertTrue(is_link($versionDir.'/.deluge-cli'));
    }
}
