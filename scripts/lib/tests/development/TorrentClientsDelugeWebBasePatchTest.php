<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/torrentClients/delugeVenv.php';

final class TorrentClientsDelugeWebBasePatchTest extends TestCase
{
    /** Fake wheel and installer keep the real post-install path free of pip/network calls. */
    private function fixture(bool $legacy = true, array $pythons = ['3.11']): array
    {
        $root = $this->pmssMakeTempDir('pmss-deluge-web-venv-').'/clients';
        mkdir($root.'/wheels', 0755, true);
        mkdir($root.'/deluge', 0755);
        $wheel = 'deluge-2.1.1-py3-none-any.whl';
        file_put_contents($root.'/wheels/'.$wheel, 'fixture wheel');
        $entry = ['client' => 'deluge', 'version' => '2.1.1', 'kind' => 'venv',
            'wheels' => [['file' => $wheel, 'sha256' => hash_file('sha256', $root.'/wheels/'.$wheel)]]];
        $install = static function ($dir, $label, $steps, $cli, $link) use ($legacy, $pythons): void {
            mkdir($dir.'/bin', 0755, true);
            file_put_contents($cli, 'fixture');
            file_put_contents($dir.'/bin/deluge-web', 'fixture');
            symlink($cli, $link);
            foreach ($pythons as $python) {
                $web = $dir.'/lib/python'.$python.'/site-packages/deluge/ui/web';
                mkdir($web, 0755, true);
                file_put_contents($web.'/server.py', $legacy
                    ? "    self.top_level.putChild(self.base.strip('/'), self.top_level)\n"
                    : "    self.site = Site(self.top_level)\n");
            }
        };
        return [$entry, $root.'/deluge/2.1.1', ['install' => $install, 'log' => static function (): void {}]];
    }

    private function server(string $dir, string $python = '3.11'): string
    {
        return $dir.'/lib/python'.$python.'/site-packages/deluge/ui/web/server.py';
    }

    public function testInstallPatchesEveryPythonTreeBeforeSuccess(): void
    {
        [$entry, $dir, $ops] = $this->fixture(true, ['3.9', '3.11']);
        $this->assertTrue(\pmssTorrentClientsDelugeVenvInstall($entry, $dir, $ops));
        foreach (['3.9', '3.11'] as $python) {
            $this->assertSame("    self.top_level.putChild(self.base.strip('/').encode(), self.top_level)\n",
                file_get_contents($this->server($dir, $python)));
        }
        $this->assertFalse(is_link($dir.'/.deluge-cli'));
    }

    public function testInstallInvokesPatcherAfterScriptsExistAndTemporaryLinkIsRemoved(): void
    {
        [$entry, $dir, $ops] = $this->fixture();
        $calls = [];
        $ops['patch'] = function (string $path, bool $dryRun, callable $log) use ($dir, &$calls): bool {
            $calls[] = [$path, $dryRun];
            $this->assertTrue(is_file($dir.'/bin/deluged'));
            $this->assertTrue(is_file($dir.'/bin/deluge-web'));
            $this->assertFalse(is_link($dir.'/.deluge-cli'));
            return \pmssPatchDelugeWebBasePathBytes($path, $dryRun, $log);
        };
        $this->assertTrue(\pmssTorrentClientsDelugeVenvInstall($entry, $dir, $ops));
        $this->assertSame([[$this->server($dir), false]], $calls);
    }

    public function testRequiredPatchFailureReturnsFalse(): void
    {
        [$entry, $dir, $ops] = $this->fixture();
        $calls = 0;
        $ops['patch'] = static function () use (&$calls): bool { $calls++; return false; };
        $this->assertFalse(\pmssTorrentClientsDelugeVenvInstall($entry, $dir, $ops));
        $this->assertSame(1, $calls);
        $this->assertSame("    self.top_level.putChild(self.base.strip('/'), self.top_level)\n",
            file_get_contents($this->server($dir)));
    }

    public function testModernServerAllowsFalseNoopPatchResult(): void
    {
        [$entry, $dir, $ops] = $this->fixture(false);
        $results = [];
        $ops['patch'] = static function ($path, $dryRun, $log) use (&$results): bool {
            $results[] = \pmssPatchDelugeWebBasePathBytes($path, $dryRun, $log);
            return end($results);
        };
        $this->assertTrue(\pmssTorrentClientsDelugeVenvInstall($entry, $dir, $ops));
        $this->assertSame([false], $results);
        $this->assertSame("    self.site = Site(self.top_level)\n", file_get_contents($this->server($dir)));
    }

    public function testFailedInstallSkipsPatcher(): void
    {
        [$entry, $dir, $ops] = $this->fixture();
        $calls = 0;
        $ops['install'] = static function (): void {};
        $ops['patch'] = static function () use (&$calls): bool { $calls++; return true; };
        $this->assertFalse(\pmssTorrentClientsDelugeVenvInstall($entry, $dir, $ops));
        $this->assertSame(0, $calls);
    }
}
