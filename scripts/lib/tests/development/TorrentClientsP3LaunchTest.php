<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/transmissionLaunch.php';
require_once dirname(__DIR__, 2).'/user/transmissionSettings.php';

final class TorrentClientsP3LaunchTest extends TestCase
{
    public function testTransmissionWatchdogHangingLauncherBacksOff(): void
    {
        $bin = $this->pmssMakeTempDir('pmss-transmission-watchdog-');
        $this->pmssWriteExecutableFiles($bin, [
            'id' => "#!/bin/sh\necho 2002\n",
            'systemctl' => "#!/bin/sh\nexit 0\n",
            'systemd-run' => "#!/bin/sh\nwhile [ \"\$1\" != '--' ]; do shift; done\nshift\n\"\$@\"\n",
            'su' => "#!/bin/sh\nshift\n[ \"\$1\" = '-c' ] || exit 9\nshift\n/bin/sh -c \"\$1\"\n",
        ]);
        $this->pmssWithPathPrefixedEnv($bin, ['PMSS_RUNTIME_DIR' => $bin.'/runtime'], function (): void {
            $command = \pmssBuildUserServiceShellCommand('alice', 'sleep 30');
            $result = \pmssUserWatchdogCommandRun($command, 1, 'alice', 'transmission');
            $this->assertTrue($result['timed_out']);
            $this->assertTrue(\pmssUserWatchdogBackoffActive('alice', 'transmission'));
        });
        $source = $this->pmssReadRepoFile('scripts/cron/transmissionInstancesCheck.php');
        $this->assertTrue(strpos($source, 'pmssUserWatchdogCommandRun(pmssBuildUserServiceShellCommand($user, $command)') !== false);
    }

    private function fixture(): array
    {
        [$root, $home, $store] = $this->pmssTorrentClientStoreFixture('pmss-p3-launch-',
            'transmission', '4.1.3', ['transmission-daemon' => "#!/bin/sh\nexit 0\n"], 't', 0700);
        mkdir($home.'/.config/transmission-daemon', 0700, true);
        mkdir($home.'/.lighttpd', 0700, true);
        $binary = $store.'/transmission/4.1.3/transmission-daemon';
        chmod($binary, 0755);
        symlink($store.'/transmission/4.1.3', $home.'/bin/transmission/default');
        $projection = $root.'/projection.json';
        file_put_contents($projection, json_encode(['versions' => [[
            'client' => 'transmission', 'version' => '4.1.3', 'kind' => 'static', 'rpcUnixSocket' => true,
        ]]]));
        $settings = \pmssTransmissionSettingsContent('{}', $home.'/.lighttpd/transmission.sock', '/user-t/transmission/');
        file_put_contents($home.'/.config/transmission-daemon/settings.json', $settings);
        return [$home, $store, $projection, $binary];
    }

    public function testDisableMarkerSkipsCronAndEverySharedLaunch(): void
    {
        [$home, $store, $projection] = $this->fixture();
        $marker = $home.'/.transmissionDisable';
        $this->assertTrue(\pmssTransmissionAccountEnabled($home));
        file_put_contents($marker, '');
        $this->assertFalse(\pmssTransmissionAccountEnabled($home));
        $unexpected = static function (): void { throw new \RuntimeException('Disabled launch did work'); };
        $this->assertFalse(\pmssTransmissionLaunch($home, [
            'storeRoot' => $store, 'projection' => $projection,
            'native' => $unexpected, 'probe' => $unexpected, 'run' => $unexpected,
        ]));
        $source = $this->pmssReadRepoFile('scripts/cron/transmissionInstancesCheck.php');
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/cron/transmissionInstancesCheck.php', [
            'pmssCronLockAcquire(', 'pmssUserWatchdogBackoffActive(',
            'pmssLighttpdTransmissionSelected(', 'pmssTransmissionAccountEnabled($home)',
            'pmssTorrentClientLauncherLivenessPids(', 'pmssTransmissionSettingsApply(',
        ]);
        $this->assertStringContainsString('if (!pmssTransmissionAccountEnabled($home)) continue;', $source);
    }

    public function testMarkerSymlinkIsNeverFollowedAndCountsAsDisabled(): void
    {
        [$home] = $this->fixture();
        $marker = $home.'/.transmissionDisable';
        $target = $home.'/marker-target';
        file_put_contents($target, 'unchanged');
        $this->pmssCreateSymlinkOrSkip($target, $marker);
        $this->assertFalse(\pmssTransmissionAccountEnabled($home));
        $this->assertSame('unchanged', file_get_contents($target));
        // A dangling link must answer the same as a live one, or root leaks whether the target exists.
        unlink($target);
        clearstatcache();
        $this->assertFalse(\pmssTransmissionAccountEnabled($home));
        unlink($marker);
        mkdir($marker);
        $this->assertTrue(\pmssTransmissionAccountEnabled($home));
    }

    public function testLaunchUsesConfigLockAndCertificateBundle(): void
    {
        [$home, $store, $projection] = $this->fixture();
        $command = '';
        $probes = 0;
        $ops = ['storeRoot' => $store, 'projection' => $projection,
            'probe' => static function () use (&$probes): bool { return ++$probes > 1; },
            'run' => static function (string $shell) use (&$command): void { $command = $shell; }]
            + self::pmssMakeCallbacks(['native' => []]);
        $this->assertTrue(\pmssTransmissionLaunch($home, $ops));
        $this->assertStringContainsString('nohup /usr/bin/setsid flock -n', $command);
        $this->assertStringContainsString('.pmss-launch.lock', $command);
        $this->assertStringContainsString('CURL_CA_BUNDLE=/etc/ssl/certs/ca-certificates.crt', $command);
        $this->assertStringContainsString(' -f -g ', $command);
    }

    public function testUnreadySettingsPreventLaunch(): void
    {
        [$home, $store, $projection] = $this->fixture();
        $path = $home.'/.config/transmission-daemon/settings.json';
        file_put_contents($path, '{"rpc_whitelist_enabled":false}');
        $calls = 0;
        $ops = ['storeRoot' => $store, 'projection' => $projection,
            'run' => static function () use (&$calls): void { $calls++; }];
        $this->assertFalse(\pmssTransmissionLaunch($home, $ops));
        $this->assertSame(0, $calls);
    }

    public function testWebHomeRequiresRealAdjacentDirectory(): void
    {
        [$home, $store, $projection, $binary] = $this->fixture();
        $web = dirname($binary).'/public_html';
        foreach (['absent', 'directory', 'symlink', 'file'] as $case) {
            if ($case === 'directory') mkdir($web);
            if ($case === 'symlink') { rmdir($web); symlink(dirname($web), $web); }
            if ($case === 'file') { unlink($web); file_put_contents($web, 'not a directory'); }
            $command = '';
            $probes = 0;
            $this->assertTrue(\pmssTransmissionLaunch($home, [
                'storeRoot' => $store, 'projection' => $projection,
                'probe' => static function () use (&$probes): bool { return ++$probes > 1; },
                'run' => static function (string $shell) use (&$command): void { $command = $shell; },
            ] + self::pmssMakeCallbacks(['native' => []])));
            if ($case === 'directory') {
                $this->assertStringContainsString(escapeshellarg('TRANSMISSION_WEB_HOME='.$web).' '.escapeshellarg($binary), $command);
            } else $this->assertStringNotContainsString('TRANSMISSION_WEB_HOME=', $command);
        }
    }

    public function testNativeIdentityUsesExecutableAndMountNamespace(): void
    {
        $root = $this->pmssMakeTempDir('pmss-p3-proc-');
        $proc = $root.'/proc';
        $binary = $root.'/transmission-daemon';
        $ownNs = readlink('/proc/self/ns/mnt');
        foreach ([101, 102, 103] as $pid) {
            $this->pmssWriteFiles($proc.'/'.$pid, ['comm' => "transmission-da\n", 'status' => "Uid:\t".posix_geteuid()." 0 0 0\n"]);
            $this->pmssWriteSymlinks($proc.'/'.$pid, [
                'ns/mnt' => $pid === 102 ? 'mnt:[other]' : $ownNs, 'exe' => $pid === 103 ? '/tmp/other-daemon' : $binary]);
        }
        $this->assertSame([101], \pmssTransmissionNativePids($binary, $proc));
    }

    public function testSecondLaunchKeepsLiveSocket(): void
    {
        [$home, $store, $projection, $binary] = $this->fixture();
        $socket = $home.'/.lighttpd/transmission.sock';
        $pidFile = $home.'/daemon.pid';
        $script = '#!/usr/bin/env php'."\n".'<?php $server = stream_socket_server('
            .var_export('unix://'.$socket, true).'); file_put_contents('
            .var_export($pidFile, true).', getmypid()); sleep(3);' . "\n";
        $this->pmssWriteExecutableFile($binary, $script);
        $ops = ['storeRoot' => $store, 'projection' => $projection];
        $this->assertTrue(\pmssTransmissionLaunch($home, $ops));
        $entry = lstat($socket);
        try {
            $this->assertFalse(\pmssTransmissionLaunch($home, $ops));
            $this->assertSame($entry['ino'], lstat($socket)['ino']);
            $output = [];
            $rc = 0;
            exec('flock -n '.escapeshellarg($home.'/.config/transmission-daemon/.pmss-launch.lock').' true', $output, $rc);
            $this->assertSame(1, $rc);
        } finally {
            $pid = (int) file_get_contents($pidFile);
            if ($pid > 0) posix_kill($pid, 15);
        }
    }

    public function testCronRejectsSymlinkedSocketBeforeConnect(): void
    {
        [$home] = $this->fixture();
        $socketPath = $home.'/.lighttpd/transmission.sock';
        $target = dirname($home).'/target.sock';
        symlink($target, $socketPath);
        $this->assertTrue(\pmssTransmissionSocketPathIsSymlink($socketPath));

        unlink($socketPath);
        $this->assertFalse(\pmssTransmissionSocketPathIsSymlink($socketPath));
        rename($home.'/.lighttpd', $home.'/lighttpd-real');
        symlink($home.'/lighttpd-real', $home.'/.lighttpd');
        $this->assertTrue(\pmssTransmissionSocketPathIsSymlink($socketPath));

        $source = (string) file_get_contents(dirname(__DIR__, 3).'/cron/transmissionInstancesCheck.php');
        $guard = strpos($source, 'if (pmssTransmissionSocketPathIsSymlink($socketPath)) continue;');
        $connect = strpos($source, "stream_socket_client('unix://'.\$socketPath");
        $this->assertTrue($guard !== false && $connect !== false && $guard < $connect);
    }
    public function testLighttpdRouteIsStaleOnlyWhenSelectionAndRenderedRouteDisagree(): void
    {
        require_once dirname(__DIR__, 2).'/lighttpd/transmissionProxy.php';
        $routed = "x\n".\pmssLighttpdTransmissionProxyBlock('alice')."y\n";
        $this->assertTrue(\pmssLighttpdTransmissionRouteStale('alice', true, "no route here\n"));
        $this->assertFalse(\pmssLighttpdTransmissionRouteStale('alice', true, $routed));
        $this->assertTrue(\pmssLighttpdTransmissionRouteStale('alice', false, $routed));
        $this->assertFalse(\pmssLighttpdTransmissionRouteStale('alice', false, "no route here\n"));
        // Another account's route never satisfies this account.
        $this->assertTrue(\pmssLighttpdTransmissionRouteStale('alice', true, \pmssLighttpdTransmissionProxyBlock('alicex')));
    }

    public function testLighttpdWatchdogReconcilesTheRouteAndLaunchCliPreparesSettings(): void
    {
        $watchdog = (string) file_get_contents($this->pmssRepoPath('scripts/cron/checkLighttpdInstances.php'));
        $converge = strpos($watchdog, 'pmssLighttpdTransmissionRouteConverge($thisUser, $homeDir);');
        $backoff = strpos($watchdog, "if (pmssUserWatchdogBackoffActive(\$thisUser, 'lighttpd')) continue;");
        $this->assertTrue($converge !== false && $backoff !== false && $converge < $backoff);
        $cli = (string) file_get_contents($this->pmssRepoPath('etc/skel/www/torrentClientsResolve.php'));
        $this->assertStringContainsString('pmssTransmissionSettingsPrepare($home) && pmssTransmissionLaunch($home)', $cli);
    }
    public function testLaunchCliPrepareRunsWithoutTheConfigurePageLoaded(): void
    {
        // The resolver CLI loads transmissionLaunch.php alone; Prepare must not depend on configure.php.
        [$home] = $this->fixture();
        $path = $home.'/.config/transmission-daemon/settings.json';
        file_put_contents($path, '{"rpc-whitelist-enabled":false}');
        $script = 'require '.var_export($this->pmssRepoPath('etc/skel/www/transmissionLaunch.php'), true).';'
            .'$ok = pmssTransmissionSettingsPrepare('.var_export($home, true).');'
            .'echo json_encode(["ok" => $ok, "ready" => pmssTransmissionSettingsReady('.var_export($home, true).')]);';
        $this->assertSame(['ok' => true, 'ready' => true], $this->pmssRunInlinePhpJson($script));
    }
}
