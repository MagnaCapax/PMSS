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
        $file = 'deluge-2.1.1-py3-none-any.whl';
        $this->pmssWriteRelativeFile($root, 'wheels/'.$file, 'fixture wheel');
        $this->pmssEnsureDir($root.'/deluge');
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
        $install = function ($dir, $label, $steps, $cli, $link) use (&$calls): void {
            $calls = [$dir, $label, $steps, $cli, $link];
            $this->pmssWriteFile($cli, 'fixture');
            $this->pmssWriteRelativeFile($dir, 'bin/deluge-web', 'fixture');
            symlink($cli, $link);
        };
        $this->assertTrue(\pmssTorrentClientsDelugeVenvInstall($entry, $root.'/deluge/2.1.1', ['install' => $install]));
        $this->assertSame($root.'/deluge/2.1.1', $calls[0]);
        $this->assertSame('Deluge 2.1.1', $calls[1]);
        $this->assertStringContainsAllStrings(['--no-index --no-deps', $root.'/wheels/'.$entry['wheels'][0]['file']], $calls[2][0][1]);
        $this->assertFalse(is_link($calls[4]));
        $this->pmssAssertRepoFileContainsString('scripts/lib/torrentClients/delugeVenv.php', "'pmssPythonVenvInstallCli'");
        $this->pmssAssertRepoFileContainsString('scripts/lib/update/apps/pythonVenv.php', 'pmssPythonVenvEnsure($venvDir');
    }

    public function testMissingOrChangedWheelStopsBeforeInstaller(): void
    {
        [$root, $entry] = $this->fixture();
        $calls = 0;
        $install = static function () use (&$calls): void { $calls++; };
        $fetched = [];
        $fetch = $this->pmssMakeArrayLogger($fetched);
        $ops = ['install' => $install, 'fetch' => $fetch];
        $this->pmssWriteRelativeFile($root, 'wheels/'.$entry['wheels'][0]['file'], 'changed');
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
        $fetch = function (string $name, string $sha256, string $path) use (&$args): void {
            $args[] = [$name, $sha256, $path];
            $this->pmssWriteFile($path, 'fixture wheel');
        };
        $install = function ($dir, $label, $steps, $cli, $link): void {
            $this->pmssWriteFile($cli, 'fixture');
            $this->pmssWriteRelativeFile($dir, 'bin/deluge-web', 'fixture');
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
        $install = function ($dir, $label, $steps, $cli, $link): void {
            $this->pmssWriteFile($cli, 'fixture');
            $this->pmssWriteRelativeFile($dir, 'bin/deluge-web', 'fixture');
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

    public function testInstallerThrowablesAttemptOnlyTemporaryLinkCleanup(): void
    {
        // All filesystem work stays in the fixture; no pip or daemon is launched.
        foreach (['RuntimeException', 'Error'] as $type) {
            foreach ([['absent', 'ok'], ['regular', 'ok'], ['valid', 'ok'], ['dangling', 'ok'],
                ['valid', 'false'], ['valid', 'throw']] as [$node, $cleanup]) {
                [$root, $entry] = $this->fixture();
                $versionDir = $root.'/deluge/2.1.1';
                $link = $versionDir.'/.deluge-cli';
                $this->pmssEnsureDir($versionDir);
                $failure = new $type('installer fault');
                $cleanupFailure = new $type('cleanup fault');
                $events = [];
                $install = function ($dir, $label, $steps, $cli, $temporary) use ($node, $failure, &$events): void {
                    $events[] = 'install';
                    if ($node === 'valid') $this->pmssWriteFile($cli, 'fixture daemon');
                    if ($node === 'valid' || $node === 'dangling') {
                        $this->assertTrue(symlink($cli, $temporary));
                    } elseif ($node === 'regular') {
                        $this->pmssWriteFile($temporary, 'fixture entry');
                    }
                    throw $failure;
                };
                $unlink = static function (string $path) use ($cleanup, $cleanupFailure, &$events): bool {
                    $events[] = ['unlink', $path];
                    if ($cleanup === 'throw') throw $cleanupFailure;
                    return $cleanup !== 'false' && unlink($path);
                };
                $caught = null;
                try {
                    \pmssTorrentClientsDelugeVenvInstall($entry, $versionDir, ['install' => $install, 'unlink' => $unlink]);
                } catch (\Throwable $error) {
                    $caught = $error;
                }
                // A false cleanup result must not swallow the active installer fault.
                $this->assertSame($cleanup === 'throw' ? $cleanupFailure : $failure, $caught);
                $wasLink = $node === 'valid' || $node === 'dangling';
                $this->assertSame($wasLink ? ['install', ['unlink', $link]] : ['install'], $events);
                $this->assertSame($wasLink && $cleanup !== 'ok', is_link($link));
                if ($node === 'regular') $this->assertSame('fixture entry', file_get_contents($link));
            }
        }
    }

    public function testPreexistingTemporaryEntriesRemainUntouched(): void
    {
        foreach (['regular', 'dangling'] as $node) {
            [$root, $entry] = $this->fixture();
            $versionDir = $root.'/deluge/2.1.1';
            $link = $versionDir.'/.deluge-cli';
            $this->pmssEnsureDir($versionDir);
            if ($node === 'regular') $this->pmssWriteFile($link, 'fixture entry');
            else $this->assertTrue(symlink($versionDir.'/missing', $link));
            $unexpected = static function (): void { throw new \RuntimeException('unexpected operation'); };
            $this->assertFalse(\pmssTorrentClientsDelugeVenvInstall($entry, $versionDir,
                ['install' => $unexpected, 'unlink' => $unexpected]));
            if ($node === 'regular') $this->assertSame('fixture entry', file_get_contents($link));
            else $this->assertSame($versionDir.'/missing', readlink($link));
        }
    }
}
