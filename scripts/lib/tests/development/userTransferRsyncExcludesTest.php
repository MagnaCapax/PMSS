<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/lib/userTransfer.php';
require_once dirname(__DIR__, 3).'/lib/lighttpd/proxyFragments.php';

/** Exercise the generated transfer filters with real rsync and local fixtures. */
class UserTransferRsyncExcludesTest extends TestCase
{
    public function testFinalPassPreservesQbittorrentProxyAndCopiesOtherFragments(): void
    {
        if ($this->pmssExecShellCommand('command -v rsync')['rc'] !== 0) {
            throw new SkipTest('requires rsync');
        }
        $root = $this->pmssMakeTempDir('pmss-transfer-proxies-');
        $source = $root.'/source';
        $this->pmssEnsureDir($source.'/.lighttpd/custom.d');
        file_put_contents($source.'/.lighttpd/custom.d/pmss-qbittorrent.conf',
            \pmssLighttpdManagedProxyFragment('qbittorrent', 'source', 45000));
        $customerFiles = [
            '.lighttpd/custom', '.lighttpd/custom.d/customer.conf', '.lighttpd/custom.d/pmss-notes.txt',
            '.lighttpd/custom.d/nested/pmss-qbittorrent.conf', 'www/public/pmss-qbittorrent.conf',
            '.lighttpd/custom.d/pmss-rclone.conf', '.lighttpd/custom.d/pmss-qbittorrent.conf.backup',
        ];
        foreach ($customerFiles as $path) {
            $this->pmssEnsureDir(dirname($source.'/'.$path));
            file_put_contents($source.'/'.$path, $path);
        }

        foreach ([false, true] as $namespaceRsync) {
            $target = $root.'/target-'.(int) $namespaceRsync;
            $this->pmssEnsureDir($target.'/.lighttpd/custom.d');
            file_put_contents($target.'/.qbittorrentPort', '46000');
            $fragment = \pmssLighttpdManagedProxyFragment('qbittorrent', 'target', 46000);
            $proxyPath = $target.'/.lighttpd/custom.d/pmss-qbittorrent.conf';
            file_put_contents($proxyPath, $fragment);
            // Force rsync to notice equal-sized fragments even within the same second.
            touch($proxyPath, 946684800);
            $cfg = ['localUser' => 'target', 'remoteUser' => 'source', 'hostname' => 'example.invalid',
                'namespaceRsync' => $namespaceRsync, 'receiver' => [
                    'uid' => 1200, 'gid' => 1200, 'home' => $target, 'sourceUid' => 1300, 'sourceGid' => 1300,
                    'uidMap' => "0 1200 1\n1300 200000 1\n", 'gidMap' => "0 1200 1\n1300 200000 1\n",
                ]];
            // Unquote the mount wrapper and, for legacy mapping, the inner account shell.
            $script = \pmssUserTransferBuildRsyncFinal($cfg);
            for ($depth = 0; $depth < ($namespaceRsync ? 1 : 2); ++$depth) {
                $script = str_replace("'\\''", "'", $script);
            }
            // Stop at rsync's destination so the legacy background shell never runs.
            $pattern = '~rsync -aHAX [^\n]+?'.preg_quote(escapeshellarg('/home/target/'), '~').'~';
            $this->assertSame(1, preg_match($pattern, $script, $matches));
            $command = preg_replace_callback("~'source@example\\.invalid:/home/source(/[^']*)'~",
                static function (array $match) use ($source): string {
                    return escapeshellarg($source.$match[1]);
                }, $matches[0]);
            $command = str_replace(escapeshellarg('/home/target/'), escapeshellarg($target.'/'), $command, $replaced);
            $this->assertSame(1, $replaced);
            $this->assertStringNotContainsString('source@example.invalid:', $command);
            $result = $this->pmssExecShellCommand($command);
            $this->assertSame(0, $result['rc'], $result['output']);

            $this->assertSame($fragment, file_get_contents($proxyPath));
            $this->assertStringContainsString('"port" => '.trim(file_get_contents($target.'/.qbittorrentPort')),
                file_get_contents($proxyPath));
            foreach ($customerFiles as $path) {
                $this->assertSame($path, file_get_contents($target.'/'.$path), 'customer file missing: '.$path);
            }
        }
    }

    public function testMainPassExcludesHomeStateButCopiesNestedNames(): void
    {
        if ($this->pmssExecShellCommand('command -v rsync')['rc'] !== 0) {
            throw new SkipTest('requires rsync');
        }
        $root = $this->pmssMakeTempDir('pmss-transfer-excludes-');
        $source = $root.'/source';
        $target = $root.'/target';
        $this->pmssEnsureDir($target);
        $homeState = [
            'www/file', 'www/rutorrent/share/file', 'session/file', '.cache/file', '.logs/file', '.local/file',
            '.lighttpd/file', '.rtorrent.rc', '.qbittorrentPort', '.config/pmss-user.json',
            '.config/qBittorrent/qBittorrent.conf', '.config/deluge/core.conf', '.config/deluge/web.conf',
            '.lighttpd.conf', '.quota', '.rtorrentExecuteRun', '.trafficData', '.trafficDataLocal',
            '.trafficDataIngress', '.trafficDataIngressLocal', 'rTorrentLog', '.bonusQuota', '.bonusTraffic',
            '.trafficLimit', '.resourceData', '.billingId', '.billingServiceId', '.billingClientId',
            '.mcxPikkumies/pikkumies/local/file', '.hermes/file',
        ];
        foreach (['', 'data/x/'] as $prefix) {
            foreach (array_merge($homeState, ['upload.pmss-tmp-123', 'dir.pmss-tmp-456/file']) as $path) {
                $file = $source.'/'.$prefix.$path;
                $this->pmssEnsureDir(dirname($file));
                file_put_contents($file, $prefix.$path);
            }
        }
        // Exact state names must not swallow neighbouring customer files either.
        file_put_contents($source.'/.rtorrent.rc.custom', 'custom');
        file_put_contents($source.'/data/x/rTorrentLog.backup', 'backup');
        $cfg = ['localUser' => 'target', 'remoteUser' => 'source', 'hostname' => 'example.invalid',
            'namespaceRsync' => true, 'receiver' => ['uid' => 1200, 'gid' => 1200, 'home' => $target]];
        $script = \pmssUserTransferBuildRsyncMain($cfg);
        // Unquote the outer namespace shell; execute only its rsync command locally.
        $script = str_replace("'\\''", "'", $script);
        $this->assertSame(1, preg_match('/rsync -aHAX [^\n]+/', $script, $matches));
        $command = str_replace([
            escapeshellarg('source@example.invalid:/home/source/'), escapeshellarg('/home/target/'),
        ], [escapeshellarg($source.'/'), escapeshellarg($target.'/')], $matches[0], $replaced);
        $this->assertSame(2, $replaced, 'both transfer endpoints must be local fixtures');
        $result = $this->pmssExecShellCommand($command);
        $this->assertSame(0, $result['rc'], $result['output']);

        foreach ($homeState as $path) {
            $this->assertFalse(file_exists($target.'/'.$path), 'home state copied: '.$path);
            $this->assertSame('data/x/'.$path, file_get_contents($target.'/data/x/'.$path), 'nested data missing: '.$path);
        }
        foreach (['', 'data/x/'] as $prefix) {
            foreach (['upload.pmss-tmp-123', 'dir.pmss-tmp-456'] as $path) {
                $this->assertFalse(file_exists($target.'/'.$prefix.$path), 'temporary entry copied: '.$prefix.$path);
            }
        }
        $this->assertSame('custom', file_get_contents($target.'/.rtorrent.rc.custom'));
        $this->assertSame('backup', file_get_contents($target.'/data/x/rTorrentLog.backup'));
    }
}
