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
        $root = $this->pmssMakeTempDir('pmss-p3-launch-');
        $home = $root.'/t';
        $store = $root.'/store';
        mkdir($home.'/bin/transmission', 0700, true);
        mkdir($home.'/.config/transmission-daemon', 0700, true);
        mkdir($home.'/.lighttpd', 0700, true);
        mkdir($store.'/transmission/4.1.3', 0755, true);
        $binary = $store.'/transmission/4.1.3/transmission-daemon';
        file_put_contents($binary, "#!/bin/sh\nexit 0\n");
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

    public function testLaunchUsesConfigLockAndCertificateBundle(): void
    {
        [$home, $store, $projection] = $this->fixture();
        $command = '';
        $probes = 0;
        $ops = ['storeRoot' => $store, 'projection' => $projection,
            'native' => static function (): array { return []; },
            'probe' => static function () use (&$probes): bool { return ++$probes > 1; },
            'run' => static function (string $shell) use (&$command): void { $command = $shell; }];
        $this->assertTrue(\pmssTransmissionLaunch($home, $ops));
        $this->assertStringContainsString('flock -n', $command);
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

    public function testNativeIdentityUsesExecutableAndMountNamespace(): void
    {
        $root = $this->pmssMakeTempDir('pmss-p3-proc-');
        $proc = $root.'/proc';
        $binary = $root.'/transmission-daemon';
        $ownNs = readlink('/proc/self/ns/mnt');
        foreach ([101, 102, 103] as $pid) {
            mkdir($proc.'/'.$pid.'/ns', 0755, true);
            file_put_contents($proc.'/'.$pid.'/comm', "transmission-da\n");
            file_put_contents($proc.'/'.$pid.'/status', "Uid:\t".posix_geteuid()." 0 0 0\n");
            symlink($pid === 102 ? 'mnt:[other]' : $ownNs, $proc.'/'.$pid.'/ns/mnt');
            symlink($pid === 103 ? '/tmp/other-daemon' : $binary, $proc.'/'.$pid.'/exe');
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
        file_put_contents($binary, $script);
        chmod($binary, 0755);
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
}
