<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/torrentClients/userLinks.php';

/** Default publication uses the same account command seam as version links. */
final class TorrentClientsDefaultLinksTest extends TestCase
{
    private function rpcMarkers(): array
    {
        return ['php/settings.php' => \PMSS_RUTORRENT_RPC_SETTINGS_MARKER,
            'plugins/httprpc/action.php' => \PMSS_RUTORRENT_RPC_HTTPRPC_MARKER,
            'plugins/erasedata/init.php' => \PMSS_RUTORRENT_RPC_ERASEDATA_MARKER];
    }

    private function fixture(): array
    {
        [$root, $home, $store] = $this->pmssTorrentClientStoreFixture('pmss-clients-default-',
            'rtorrent', '0.16.25', []);
        $catalog = $root.'/catalog.json';
        copy(dirname(__DIR__, 4).'/etc/seedbox/config/torrentClients.json', $catalog);
        $skeleton = $root.'/rc.custom';
        copy(dirname(__DIR__, 4).'/etc/skel/.rtorrent.rc.custom', $skeleton);
        foreach ($this->rpcMarkers() as $file => $marker) {
            $source = dirname(__DIR__, 4).'/etc/skel/www/rutorrent/'.$file;
            // Use delivered bytes so a marker typo cannot make this a self-fulfilling fixture.
            $this->assertStringContainsString($marker, file_get_contents($source));
            mkdir(dirname($home.'/www/rutorrent/'.$file), 0755, true);
            copy($source, $home.'/www/rutorrent/'.$file);
        }
        $ops = ['root' => $store, 'homeRoot' => $root, 'catalog' => $catalog,
            'rtorrentCustomSkeleton' => $skeleton,
            'run' => $this->pmssAccountPathCommandRunner()];
        return [$home, $store, $ops];
    }

    public function testNeverSwitchedAccountGetsCatalogDefaultIdempotently(): void
    {
        [$home, $store, $ops] = $this->fixture();
        for ($pass = 0; $pass < 2; $pass++) {
            $this->assertTrue(\pmssTorrentClientsUserLinksConverge('t', $home, $ops));
            $this->assertSame($store.'/rtorrent/0.16.25', readlink($home.'/bin/rtorrent/default'));
            $this->assertSame($store.'/rtorrent/0.16.25', readlink($home.'/bin/rtorrent/0.16.25'));
        }
    }

    public function testCustomRcMatchingCurrentSkeletonAllowsSeeding(): void
    {
        [$home, $store, $ops] = $this->fixture();
        copy($ops['rtorrentCustomSkeleton'], $home.'/.rtorrent.rc.custom');
        $this->assertTrue(\pmssTorrentClientsUserLinksConverge('t', $home, $ops));
        $this->assertSame($store.'/rtorrent/0.16.25', readlink($home.'/bin/rtorrent/default'));
    }

    public function testEditedOrNonRegularCustomRcPreventsSeeding(): void
    {
        foreach (['edited', 'symlink', 'dangling', 'directory', 'unreadable'] as $kind) {
            [$home, $store, $ops] = $this->fixture();
            $custom = $home.'/.rtorrent.rc.custom';
            if ($kind === 'edited') file_put_contents($custom, 'customer configuration');
            elseif ($kind === 'directory') mkdir($custom);
            elseif ($kind === 'unreadable') {
                copy($ops['rtorrentCustomSkeleton'], $custom);
                chmod($custom, 0000);
            } else symlink($kind === 'symlink' ? $ops['rtorrentCustomSkeleton'] : $home.'/missing', $custom);
            try {
                $this->assertTrue(\pmssTorrentClientsUserLinksConverge('t', $home, $ops));
                $this->assertFalse(is_link($home.'/bin/rtorrent/default'));
                $this->assertSame($store.'/rtorrent/0.16.25', readlink($home.'/bin/rtorrent/0.16.25'));
            } finally {
                if ($kind === 'unreadable') chmod($custom, 0600);
            }
        }
    }

    public function testUnavailableSkeletonPreventsSeedingEvenWithoutCustomRc(): void
    {
        foreach (['missing', 'unreadable'] as $kind) {
            [$home, $store, $ops] = $this->fixture();
            if ($kind === 'missing') unlink($ops['rtorrentCustomSkeleton']);
            else chmod($ops['rtorrentCustomSkeleton'], 0000);
            try {
                $this->assertTrue(\pmssTorrentClientsUserLinksConverge('t', $home, $ops));
                $this->assertFalse(is_link($home.'/bin/rtorrent/default'));
                $this->assertSame($store.'/rtorrent/0.16.25', readlink($home.'/bin/rtorrent/0.16.25'));
            } finally {
                if ($kind === 'unreadable') chmod($ops['rtorrentCustomSkeleton'], 0600);
            }
        }
    }

    public function testEveryRpcPatchRequiresARegularReadableNonSymlinkFileWithItsMarker(): void
    {
        foreach ($this->rpcMarkers() as $file => $marker) {
            foreach (['unpatched', 'missing', 'symlink', 'dangling', 'directory', 'unreadable'] as $kind) {
                [$home, $store, $ops] = $this->fixture();
                $path = $home.'/www/rutorrent/'.$file;
                unlink($path);
                if ($kind === 'unpatched') file_put_contents($path, 'legacy file without compatibility patch');
                elseif ($kind === 'directory') mkdir($path);
                elseif ($kind === 'unreadable') {
                    file_put_contents($path, $marker);
                    chmod($path, 0000);
                } elseif ($kind !== 'missing') symlink($kind === 'symlink'
                    ? dirname(__DIR__, 4).'/etc/skel/www/rutorrent/'.$file : $home.'/missing', $path);
                try {
                    $this->assertTrue(\pmssTorrentClientsUserLinksConverge('t', $home, $ops));
                    $this->assertFalse(is_link($home.'/bin/rtorrent/default'));
                    $this->assertSame($store.'/rtorrent/0.16.25', readlink($home.'/bin/rtorrent/0.16.25'));
                } finally {
                    if ($kind === 'unreadable') chmod($path, 0600);
                }
            }
        }
    }

    public function testDanglingPriorSwitchRecordPreventsSeeding(): void
    {
        [$home, $store, $ops] = $this->fixture();
        mkdir($home.'/.config/pmss/torrentClients', 0700, true);
        symlink($home.'/missing', $home.'/.config/pmss/torrentClients/rtorrent.lastRun');
        $this->assertTrue(\pmssTorrentClientsUserLinksConverge('t', $home, $ops));
        $this->assertFalse(is_link($home.'/bin/rtorrent/default'));
    }

    public function testPriorSwitchIncludingSystemPreventsDefaultCreation(): void
    {
        foreach (['system', '0.9.8-r16', ''] as $choice) {
            [$home, $store, $ops] = $this->fixture();
            mkdir($home.'/.config/pmss/torrentClients', 0700, true);
            $lastRun = $home.'/.config/pmss/torrentClients/rtorrent.lastRun';
            file_put_contents($lastRun, $choice);
            $this->assertTrue(\pmssTorrentClientsUserLinksConverge('t', $home, $ops));
            $this->assertFalse(is_link($home.'/bin/rtorrent/default'));
            $this->assertSame($choice, file_get_contents($lastRun));
            $this->assertSame($store.'/rtorrent/0.16.25', readlink($home.'/bin/rtorrent/0.16.25'));
        }
    }

    public function testExistingDefaultFilesDirectoriesAndLinksSurvive(): void
    {
        foreach (['file', 'directory', 'link', 'dangling'] as $kind) {
            [$home, $store, $ops] = $this->fixture();
            $default = $home.'/bin/rtorrent/default';
            if ($kind === 'file') file_put_contents($default, 'keep');
            elseif ($kind === 'directory') mkdir($default);
            else symlink($kind === 'link' ? $store.'/rtorrent/0.16.25' : '/missing', $default);
            $before = lstat($default);
            $this->assertTrue(\pmssTorrentClientsUserLinksConverge('t', $home, $ops));
            $this->assertSame($before['ino'], lstat($default)['ino']);
            if ($kind === 'file') $this->assertSame('keep', file_get_contents($default));
            if ($kind === 'link' || $kind === 'dangling') {
                $this->assertSame($kind === 'link' ? $store.'/rtorrent/0.16.25' : '/missing', readlink($default));
            }
        }
    }

    public function testSystemCatalogDefaultCreatesOnlyVersionLinks(): void
    {
        [$home, $store, $ops] = $this->fixture();
        $catalog = json_decode(file_get_contents($ops['catalog']), true);
        foreach ($catalog as &$entry) {
            if ($entry['client'] === 'rtorrent') $entry['isDefault'] = $entry['kind'] === 'system';
        }
        unset($entry);
        file_put_contents($ops['catalog'], json_encode($catalog));
        $this->assertTrue(\pmssTorrentClientsUserLinksConverge('t', $home, $ops));
        $this->assertFalse(is_link($home.'/bin/rtorrent/default'));
        $this->assertSame($store.'/rtorrent/0.16.25', readlink($home.'/bin/rtorrent/0.16.25'));
    }

    public function testMissingOrInvalidCatalogDoesNotFailVersionConvergence(): void
    {
        foreach ([null, '{invalid', '[]'] as $contents) {
            [$home, $store, $ops] = $this->fixture();
            if ($contents === null) unlink($ops['catalog']);
            else file_put_contents($ops['catalog'], $contents);
            $this->assertTrue(\pmssTorrentClientsUserLinksConverge('t', $home, $ops));
            $this->assertFalse(is_link($home.'/bin/rtorrent/default'));
            $this->assertSame($store.'/rtorrent/0.16.25', readlink($home.'/bin/rtorrent/0.16.25'));
        }
    }

    public function testMissingOrUnsafeStoreDefaultIsNotPublished(): void
    {
        foreach (['missing', 'symlink', 'writable'] as $kind) {
            [$home, $store, $ops] = $this->fixture();
            $version = $store.'/rtorrent/0.16.25';
            if ($kind === 'writable') chmod($version, 0777);
            else {
                rmdir($version);
                if ($kind === 'symlink') symlink($home, $version);
            }
            $this->assertTrue(\pmssTorrentClientsUserLinksConverge('t', $home, $ops));
            $this->assertFalse(is_link($home.'/bin/rtorrent/default'));
            $this->assertFalse(is_link($home.'/bin/rtorrent/0.16.25'));
        }
    }
    public function testTransmissionStoreDefaultIsNeverSeeded(): void
    {
        [$home, $store, $ops] = $this->fixture();
        // The shipped catalog marks a Transmission store build as default; a link would mark it selected.
        $default = array_values(array_filter(json_decode(file_get_contents($ops['catalog']), true),
            static function ($entry) { return $entry['client'] === 'transmission' && $entry['isDefault']; }));
        $this->assertSame(1, count($default));
        mkdir($store.'/transmission/'.$default[0]['version'], 0755, true);
        $this->assertTrue(\pmssTorrentClientsUserLinksConverge('t', $home, $ops));
        $this->assertSame($store.'/transmission/'.$default[0]['version'],
            readlink($home.'/bin/transmission/'.$default[0]['version']));
        $this->assertFalse(is_link($home.'/bin/transmission/default'));
        $this->assertSame($store.'/rtorrent/0.16.25', readlink($home.'/bin/rtorrent/default'));
    }
}
