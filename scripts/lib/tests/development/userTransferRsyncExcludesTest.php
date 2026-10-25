<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/lib/userTransfer.php';

/** Exercise the generated main-pass filters with real rsync and local fixtures. */
class UserTransferRsyncExcludesTest extends TestCase
{
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
