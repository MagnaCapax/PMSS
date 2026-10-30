<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/torrentClientsResolve.php';

/** Keep the rTorrent executor inert until an account selects a published store build. */
final class TorrentClientsP4R0Test extends TestCase
{
    private function fixture(): array
    {
        [$root, $home, $store] = $this->pmssTorrentClientStoreFixture('pmss-p4-r0-',
            'rtorrent', '0.9.8-r16', ['rtorrent' => 'fixture'], 't@localhost');
        $projection = $root.'/torrentClients.json';
        return [$home, $store, $projection];
    }

    public function testNoSelectionKeepsExactLegacyLaunchCommand(): void
    {
        [$home, $store, $projection] = $this->fixture();
        $this->assertSame('/usr/local/bin/rtorrent',
            \pmssRtorrentExecutorCommand($home, $projection, $store));
        $source = (string) file_get_contents(dirname(__DIR__, 4).'/etc/skel/.rtorrentExecute.php');
        $this->assertStringContainsAllStrings(["\$rtorrentCommand = '/usr/local/bin/rtorrent';",
            'passthru($rtorrentCommand, $rtorrentRc);'], $source);
    }

    public function testPublishedStoreSelectionResolvesBinary(): void
    {
        [$home, $store, $projection] = $this->fixture();
        $binary = $store.'/rtorrent/0.9.8-r16/rtorrent';
        symlink($store.'/rtorrent/0.9.8-r16', $home.'/bin/rtorrent/default');
        file_put_contents($projection, json_encode(['versions' => [
            ['client' => 'rtorrent', 'version' => '0.9.8-r16', 'kind' => 'static'],
        ]]));
        $this->assertSame($binary, \pmssTorrentClientResolve($home, 'rtorrent', $projection, $store));
        $caDefault = 'branch = "network.http.cacert=", "cat=", "network.http.capath=", "cat=", '
            .'"network.http.cacert.set=/etc/ssl/certs/ca-certificates.crt"';
        $this->assertSame(escapeshellarg($binary).' -O '.escapeshellarg($caDefault),
            \pmssRtorrentExecutorCommand($home, $projection, $store));
        $source = (string) file_get_contents(dirname(__DIR__, 4).'/etc/skel/.rtorrentExecute.php');
        $loop = substr($source, strpos($source, 'while (true) {'));
        $this->assertStringContainsString('pmssRtorrentExecutorCommand(__DIR__)', $loop);
    }

    public function testResolverFailureFallsBackAndExecutorKeepsLooping(): void
    {
        [$home, $store, $projection] = $this->fixture();
        symlink($store.'/rtorrent/0.9.8-r16', $home.'/bin/rtorrent/default');
        // An absent projection makes the P0 resolver reject the store selection.
        $this->assertSame(null, \pmssTorrentClientResolve($home, 'rtorrent', $projection, $store));
        $this->assertSame('/usr/local/bin/rtorrent',
            \pmssRtorrentExecutorCommand($home, $projection, $store));
        $source = (string) file_get_contents(dirname(__DIR__, 4).'/etc/skel/.rtorrentExecute.php');
        $loop = substr($source, strpos($source, 'while (true) {'));
        $this->assertStringContainsAllStrings(['catch (Throwable $error)', "\$rtorrentCommand = '/usr/local/bin/rtorrent';"], $loop);
        $this->assertFalse(strpos($loop, 'exit(') !== false);
    }

    public function testAccountLocalSelectionDoesNotChangeSystemCommand(): void
    {
        [$home, $store, $projection] = $this->fixture();
        mkdir($home.'/bin/rtorrent/local');
        file_put_contents($home.'/bin/rtorrent/local/rtorrent', 'fixture');
        symlink('local', $home.'/bin/rtorrent/default');
        $this->assertSame('/usr/local/bin/rtorrent',
            \pmssRtorrentExecutorCommand($home, $projection, $store));
    }

    public function testDeadPgrepGuardIsGoneAndFlockRemains(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4).'/etc/skel/.rtorrentExecute.php');
        $this->assertFalse(strpos($source, 'pgrep -u') !== false);
        $this->assertStringContainsString('@flock($lockHandle, LOCK_EX | LOCK_NB)', $source);
    }
}
