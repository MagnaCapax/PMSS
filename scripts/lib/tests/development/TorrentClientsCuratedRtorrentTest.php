<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/torrentClients/catalog.php';
require_once dirname(__DIR__, 2).'/torrentClients/store.php';
require_once dirname(__DIR__, 2).'/torrentClients/projection.php';
require_once dirname(__DIR__, 2).'/user/watchdog.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureSettings.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClientsSwitch.php';

/** Exercise the pinned catalog and customer selection without a live daemon. */
final class TorrentClientsCuratedRtorrentTest extends TestCase
{
    private function entries(): array
    {
        return array_values(array_filter(\pmssTorrentClientsCatalogLoad(
            dirname(__DIR__, 4).'/etc/seedbox/config/torrentClients.json'), static function ($entry) {
                return $entry['client'] === 'rtorrent';
            }));
    }

    public function testPinnedStaticEntryPreservesSystemBuildWith016Default(): void
    {
        [$system, $entry] = $this->entries();
        $this->assertSame(['client' => 'rtorrent', 'version' => 'system', 'url' => [], 'sha256' => '',
            'kind' => 'system', 'compatFamily' => 'rtorrent', 'cve' => [], 'disabled' => false,
            'isDefault' => false, 'binary' => '/usr/local/bin/rtorrent'], $system);
        $this->assertTrue($this->entries()[2]['isDefault']);
        $this->assertSame('0.9.8-r16', $entry['version']);
        $this->assertSame('static', $entry['kind']);
        $this->assertSame('0.9.8', $entry['compatFamily']);
        $this->assertSame(['https://github.com/jesec/rtorrent/releases/download/v0.9.8-r16/rtorrent-linux-amd64'], $entry['url']);
        $this->assertSame('974c367b92eca69b85de8afde4937559b8396dc996694da2e7cb4fdc38dc8b40', $entry['sha256']);
        $this->assertSame(1, preg_match('/\A[a-f0-9]{64}\z/', $entry['sha256']));
        $this->assertFalse($entry['isDefault']);
        // Offered again with the bundled ruTorrent compatibility patches (ADR 0146).
        $this->assertFalse($entry['disabled']);
    }

    public function testInstalledProjectionReachesGenericPickerAndExecutorLaunch(): void
    {
        $root = $this->pmssMakeTempDir('pmss-rtorrent-curated-');
        $store = $root.'/pmss/clients';
        mkdir(dirname($store), 0755);
        $entry = $this->entries()[1];
        // Executable fixture bytes stand in for the separately verified upstream artifact.
        $bytes = "#!/bin/sh\nprintf 'selected-rtorrent'\n";
        $entry['sha256'] = hash('sha256', $bytes);
        // Exercise the mechanism, not the catalog's current offer.
        $entry['disabled'] = false;
        $this->pmssWithEnv(['PMSS_TORRENT_CLIENTS_STORE_ROOT' => $store], function () use ($entry, $root, $store, $bytes): void {
            $projection = $root.'/public/catalog.json';
            $this->assertTrue(\pmssTorrentClientsProjectionWrite([$entry], $projection));
            $this->assertSame([], \pmssConfigureTorrentClientVersionsRead($projection, 'rtorrent'));
            $this->assertSame(['rtorrent/0.9.8-r16' => 'installed'], \pmssTorrentClientsStoreConverge([$entry], [
                'root' => $store,
                'install' => static function ($row, $binary) use ($bytes): bool {
                    return file_put_contents($binary, $bytes) === strlen($bytes);
                },
            ] + self::pmssMakeCallbacks(['free' => 10 * 1024 ** 3, 'size' => 0, 'log' => null])));
            $this->assertTrue(\pmssTorrentClientsProjectionWrite([$entry], $projection));
            $this->assertSame(['0.9.8-r16'], array_keys(\pmssConfigureTorrentClientVersionsRead($projection, 'rtorrent')));
            $home = $root.'/t';
            mkdir($home.'/bin/rtorrent', 0755, true);
            $binary = $store.'/rtorrent/0.9.8-r16/rtorrent';
            symlink(dirname($binary), $home.'/bin/rtorrent/default');
            $this->assertSame($binary, \pmssTorrentClientResolve($home, 'rtorrent', $projection, $store));
            exec(\pmssRtorrentExecutorCommand($home, $projection, $store), $output, $rc);
            $this->assertSame(0, $rc);
            $this->assertSame(['selected-rtorrent'], $output);
            $this->assertFalse(is_link($store.'/rtorrent/default'));
            unlink($binary);
            $this->assertTrue(\pmssTorrentClientsProjectionWrite([$entry], $projection));
            $this->assertSame([], \pmssConfigureTorrentClientVersionsRead($projection, 'rtorrent'));
        });
    }

    public function testSystemRepointAndRestartPreflightPreserveStoppedAccounts(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rtorrent-switch-');
        mkdir($home.'/bin/rtorrent', 0755, true);
        symlink('/missing', $home.'/bin/rtorrent/default');
        $this->assertTrue(\pmssConfigureTorrentClientOtherRepoint($home, 'rtorrent', 'system'));
        $this->assertFalse(is_link($home.'/bin/rtorrent/default'));
        $this->assertTrue(\pmssConfigureTorrentClientOtherStop($home, 'rtorrent'));
        $this->assertFalse(\pmssConfigureTorrentClientOtherStart($home, 'rtorrent', 'system'));
        $this->assertFalse(\pmssConfigureTorrentClientOtherStart($home, 'rtorrent', '0.9.8-r16'));
        $this->assertFalse(file_exists($home.'/www/.rtorrentRestart'));
        file_put_contents($home.'/.rtorrentDisable', '');
        $result = \pmssConfigureTorrentClientOtherSwitch($home,
            ['client' => 'rtorrent', 'version' => '0.9.8-r16'],
            ['versions' => ['0.9.8-r16' => ['kind' => 'static']]]);
        $this->assertSame(409, $result['status']);
        $this->assertFalse(is_dir($home.'/.config'));
    }

    public function testWatchdogAcceptsSameMajorMainThreadWithSelectedStorePath(): void
    {
        $root = $this->pmssMakeTempDir('pmss-rtorrent-watchdog-');
        $probe = $root.'/pgrep';
        // Mimic pgrep matching the 0.9.8 main thread; never inspect a live PID.
        $this->pmssWriteExecutableFile($probe, "#!/bin/sh\ncase \"\$*\" in *'^rtorrent'*) printf '123\\n';; *) exit 1;; esac\n");
        $this->pmssWithEnv(['PATH' => $root.':'.getenv('PATH')], function (): void {
            $this->assertSame([123], \pmssUserWatchdogProcessPids('testuser', '^rtorrent'));
        });
        $source = file_get_contents(dirname(__DIR__, 3).'/cron/checkRtorrent.php');
        $this->assertStringContainsString("pmssUserWatchdogProcessPids(\$user, '^rtorrent')", $source);
    }

    public function testPickerAndRestartReuseDeliveredCustomerFiles(): void
    {
        $root = dirname(__DIR__, 4);
        $configure = file_get_contents($root.'/etc/skel/www/configure.php');
        $this->assertStringContainsString('pmssConfigureTorrentClientCardsBuild($home)', $configure);
        $cards = file_get_contents($root.'/etc/skel/www/configureTorrentClientsCard.php');
        $this->assertStringContainsString("'rtorrent' => 'rTorrent'", $cards);
        $this->assertStringContainsString('pmssConfigureTorrentClientVersionsOffered($client, $path)', $cards);
        $delivery = file_get_contents($root.'/scripts/lib/update/users/filesystem.php');
        foreach (['configureTorrentClientsOther.php', 'configureTorrentClientsSwitch.php',
            'configureTorrentClientsVersion.php', 'configureTorrentClientsCard.php',
            'torrentClientsResolve.php', '.rtorrentRestart.php'] as $file)
            $this->assertStringContainsString("'".($file[0] === '.' ? '' : 'www/').$file."'", $delivery);
        $restart = file_get_contents($root.'/etc/skel/.rtorrentRestart.php');
        $this->assertStringContainsString("'rtorrent([ -]main)?'", $restart);
        $this->assertStringContainsString('screen -fa -d -m ./.rtorrentExecute.php', $restart);
        // A stale executor exits with a fresh run stamp; the consumer must look at the executor lock as well.
        $this->assertStringContainsString("flock(\$executorLock, LOCK_EX | LOCK_NB)", $restart);
        $this->assertStringContainsString('$lastRun == 0 or $executorIdle or', $restart);
        // Stop in Apps must hold: the executor leaves instead of relaunching while the stop marker exists.
        $executor = file_get_contents($root.'/etc/skel/.rtorrentExecute.php');
        $stopCheck = strpos($executor, "if (file_exists('.rtorrentDisable'))");
        $this->assertTrue($stopCheck !== false && $stopCheck < strpos($executor, 'passthru($rtorrentCommand'));
    }
    public function testRtorrent016EntriesArePinnedAndLaunchWithDeprecatedRedirects(): void
    {
        $this->assertSame(['system', '0.9.8-r16', '0.16.25', '0.16.26', '0.9.8-stickz7.2'],
            array_column($this->entries(), 'version'));
        foreach (['0.16.25' => '84f2b40561f353249fa463d1f79a8d3f6c5ce886cbd974372d4e5553201e2f49',
            '0.16.26' => '27a5150d55e6eeec7db95d4808cb51a32025e6171a23897b3a5ce173834cd3be'] as $version => $sha256) {
            $entry = $this->entries()[$version === '0.16.25' ? 2 : 3];
            $this->assertSame('static', $entry['kind']);
            $this->assertSame('0.16', $entry['compatFamily']);
            $this->assertSame(['https://pulsedmedia.com/remote/pkg/rtorrent-'.$version.'-static-amd64-r1'], $entry['url']);
            $this->assertSame($sha256, $entry['sha256']);
            $this->assertSame($version === '0.16.25', $entry['isDefault']);
            $this->assertFalse($entry['disabled']);
            $this->assertSame([], $entry['cve']);
        }
        $root = $this->pmssMakeTempDir('pmss-rtorrent-016-');
        $store = $root.'/clients';
        $projection = $root.'/catalog.json';
        $rows = [];
        foreach ($this->entries() as $row) {
            if ($row['kind'] === 'system') continue;
            mkdir($store.'/rtorrent/'.$row['version'], 0755, true);
            // The fixture prints its argv so the test sees the exact launch arguments.
            $this->pmssWriteExecutableFile($store.'/rtorrent/'.$row['version'].'/rtorrent', "#!/bin/sh\nprintf '%s|' \"\$@\"\n");
            $row['disabled'] = false;
            $rows[] = $row;
        }
        $this->pmssWithEnv(['PMSS_TORRENT_CLIENTS_STORE_ROOT' => $store], function () use ($rows, $projection): void {
            $this->assertTrue(\pmssTorrentClientsProjectionWrite($rows, $projection));
        });
        $home = $root.'/t';
        mkdir($home.'/bin/rtorrent', 0755, true);
        $caDefault = 'branch = "network.http.cacert=", "cat=", "network.http.capath=", "cat=", '
            .'"network.http.cacert.set=/etc/ssl/certs/ca-certificates.crt"';
        // Old account configs need no regeneration, and every family receives one shell-safe option.
        file_put_contents($home.'/.rtorrent.rc', "try_import = .rtorrent.rc.custom\n");
        file_put_contents($home.'/.rtorrent.rc.custom', "network.http.cacert.set = /custom/ca.pem\n");
        foreach (['0.16.25', '0.16.26', '0.9.8-r16', '0.9.8-stickz7.2'] as $version) {
            symlink($store.'/rtorrent/'.$version, $home.'/bin/rtorrent/default');
            $output = [];
            exec(\pmssRtorrentExecutorCommand($home, $projection, $store), $output, $rc);
            $this->assertSame([0, ['-O|'.$caDefault.'|'.(strpos($version, '0.16.') === 0 ? '-D|' : '')]], [$rc, $output]);
            $this->assertSame("try_import = .rtorrent.rc.custom\n", file_get_contents($home.'/.rtorrent.rc'));
            $this->assertSame("network.http.cacert.set = /custom/ca.pem\n", file_get_contents($home.'/.rtorrent.rc.custom'));
            unlink($home.'/bin/rtorrent/default');
        }
        $this->assertFalse(\pmssRtorrentDeprecatedRedirects('0.16.25', $root.'/missing.json'));
    }

    public function testTemplateGuardsTheOnlyRcLinesThatDifferBetweenFamilies(): void
    {
        $rc = file_get_contents(dirname(__DIR__, 4).'/etc/seedbox/config/template.rtorrent.rc');
        // 0.16 rejects encoding.add even with -D; it must only run inside the 0.9.8 branch.
        $this->assertSame(1, preg_match_all('/encoding\.add/', preg_replace('/^#.*$/m', '', $rc)));
        $this->assertStringContainsString('branch = ((equal, ((system.client_version)), ((cat, "0.9.8")))), '
            .'((encoding.add, utf8)), ((network.bind_address.set, "0.0.0.0"))', $rc);
        $this->assertSame(0, preg_match('/^encoding\.add/m', $rc));
        $this->assertSame(1, preg_match('/^rtorrent([ -]main)?$/', 'rtorrent-main'));
        $this->assertSame(1, preg_match('/^rtorrent([ -]main)?$/', 'rtorrent main'));
        $this->assertSame(0, preg_match('/^rtorrent([ -]main)?$/', 'rtorrent-disk'));
    }
    public function testStickzForkEntryIsPinnedOptInZeroNineEightFamily(): void
    {
        $entry = $this->entries()[4];
        $this->assertSame('0.9.8-stickz7.2', $entry['version']);
        $this->assertSame('static', $entry['kind']);
        // Same rc and process-name family as 0.9.8, so it launches without -D.
        $this->assertSame('0.9.8', $entry['compatFamily']);
        $this->assertSame(['https://pulsedmedia.com/remote/pkg/rtorrent-stickz-7.2-static-amd64-r1'], $entry['url']);
        $this->assertSame('726f9ce28d2675425d45a624b95957b22f2777d4ccacf3634e5f9f7da3cf003c', $entry['sha256']);
        $this->assertFalse($entry['isDefault']);
        $this->assertFalse($entry['disabled']);
    }
    public function testProjectionDoesNotOfferDisabledInstalledBuilds(): void
    {
        $root = $this->pmssMakeTempDir('pmss-rtorrent-disabled-');
        $store = $root.'/clients';
        $projection = $root.'/catalog.json';
        $rows = [];
        foreach ($this->entries() as $row) {
            if ($row['kind'] === 'system') continue;
            mkdir($store.'/rtorrent/'.$row['version'], 0755, true);
            file_put_contents($store.'/rtorrent/'.$row['version'].'/rtorrent', "#!/bin/sh\n");
            // Model withdrawal independently of which versions the shipped catalog offers.
            $row['disabled'] = $row['version'] !== '0.9.8-stickz7.2';
            $row['isDefault'] = false;
            $rows[] = $row;
        }
        $this->pmssWithEnv(['PMSS_TORRENT_CLIENTS_STORE_ROOT' => $store], function () use ($rows, $projection): void {
            $this->assertTrue(\pmssTorrentClientsProjectionWrite($rows, $projection));
        });
        // Every installed build is on disk; only the enabled one is offered.
        $this->assertSame(['0.9.8-stickz7.2'], array_keys(\pmssConfigureTorrentClientVersionsRead($projection, 'rtorrent')));
        $home = $root.'/t';
        mkdir($home.'/bin/rtorrent', 0755, true);
        symlink($store.'/rtorrent/0.16.25', $home.'/bin/rtorrent/default');
        // An account that selected a now-disabled build runs the system build again.
        $this->assertSame('/usr/local/bin/rtorrent', \pmssRtorrentExecutorCommand($home, $projection, $store));
    }
}
