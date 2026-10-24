<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/torrentClients/postSetup.php';
require_once dirname(__DIR__, 2).'/user/recreateRestore.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/torrentClientsResolve.php';

/** Transfer and recreate fixtures use t@localhost and an isolated home root. */
final class TorrentClientsP2ConvergenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pmssTrackEnvKeys(['PMSS_DRY_RUN']);
    }

    private function fixture(string $name): array
    {
        $root = $this->pmssMakeTempDir('pmss-p2-'.$name.'-');
        $home = $root.'/t';
        $this->pmssEnsureDir($home.'/bin/qbittorrent');
        $this->pmssEnsureDir($root.'/store/qbittorrent');
        $this->pmssWriteFile($root.'/catalog.json', (string) file_get_contents(dirname(__DIR__, 4).'/etc/seedbox/config/torrentClients.json'));
        $this->pmssWriteFile($root.'/projection.json', '{"versions":[]}');
        $events = (object) ['logs' => []];
        $ops = [
            'homeRoot' => $root, 'root' => $root.'/store',
            'catalog' => $root.'/catalog.json', 'projection' => $root.'/projection.json',
            'run' => static function (string $user, string $path, array $paths, string $command): bool {
                $output = []; $rc = 1;
                exec($command, $output, $rc);
                return $rc === 0;
            },
            'log' => static function (string $user, string $message) use ($events): void {
                $events->logs[] = [$user, $message];
            },
        ];
        return [$root, $home, $ops, $events];
    }

    public function testProjectionReaderRejectsUnsafeAndMalformedFiles(): void
    {
        $root = $this->pmssMakeTempDir('pmss-projection-reader-');
        $path = $root.'/projection.json';
        $entry = ['client' => 'qbittorrent', 'version' => '5.1.0'];
        $this->pmssWriteFile($path, json_encode(['versions' => [$entry]]));
        $this->assertSame([$entry], \pmssTorrentClientsProjectionVersionsRead($path));
        symlink($path, $root.'/linked.json');
        $this->assertSame([], \pmssTorrentClientsProjectionVersionsRead($root.'/linked.json'));
        link($path, $root.'/hardlinked.json');
        $this->assertSame([], \pmssTorrentClientsProjectionVersionsRead($path));
        unlink($root.'/hardlinked.json');
        $this->pmssWriteFile($path, str_repeat(' ', 65537));
        $this->assertSame([], \pmssTorrentClientsProjectionVersionsRead($path));
        $this->pmssWriteFile($path, '{"versions":false}');
        $this->assertSame([], \pmssTorrentClientsProjectionVersionsRead($path));
    }

    public function testConvergesLinksAndPreservesSelectionWithoutHostSetup(): void
    {
        [$root, $home, $ops, $events] = $this->fixture('selection');
        $this->pmssWriteFile($root.'/store/qbittorrent/5.1.0/qbittorrent-nox', 'binary');
        $this->pmssWriteFile($root.'/projection.json', '{"versions":[{"client":"qbittorrent","version":"5.1.0","kind":"static"}]}');
        $target = $root.'/store/qbittorrent/5.1.0';
        symlink($target, $home.'/bin/qbittorrent/default');
        \pmssTorrentClientsPostSetupConverge('t', $home, $ops);
        $this->assertSame($target, readlink($home.'/bin/qbittorrent/5.1.0'));
        $this->assertSame($target, readlink($home.'/bin/qbittorrent/default'));
        $this->assertSame([], $events->logs);
    }

    public function testCustomDefaultSurvivesConvergenceWithoutWarning(): void
    {
        [$root, $home, $ops, $events] = $this->fixture('custom');
        $this->pmssWriteFile($home.'/bin/qbittorrent/custom/qbittorrent-nox', 'custom');
        symlink('custom', $home.'/bin/qbittorrent/default');
        \pmssTorrentClientsPostSetupConverge('t', $home, $ops);
        $this->assertSame('custom', readlink($home.'/bin/qbittorrent/default'));
        $this->assertSame([], $events->logs);
    }

    public function testUnavailableSelectionFallsBackWithNoticeAndCustomBuildSurvives(): void
    {
        [$root, $home, $ops, $events] = $this->fixture('fallback');
        $this->pmssWriteFile($home.'/bin/qbittorrent/custom/qbittorrent-nox', 'custom');
        symlink($root.'/store/qbittorrent/old', $home.'/bin/qbittorrent/default');
        \pmssTorrentClientsPostSetupConverge('t', $home, $ops);
        $this->assertFalse(is_link($home.'/bin/qbittorrent/default'));
        $this->assertSame('custom', file_get_contents($home.'/bin/qbittorrent/custom/qbittorrent-nox'));
        $this->assertStringContainsString('using the host default', (string) file_get_contents($home.'/.config/pmss/torrentClients/qbittorrent.notice'));
        $this->assertSame(1, count($events->logs));
        $this->assertSame(null, \pmssTorrentClientResolve($home, 'qbittorrent'));
        $launcher = (string) file_get_contents(dirname(__DIR__, 3).'/cron/checkQbittorrentInstances.php');
        $this->assertStringContainsString('[ ! -L ~/bin/qbittorrent/default ]', $launcher);
        $this->assertStringContainsString('[ -L ~/bin/qbittorrent/default ] && (cd ~; php', $launcher);
        $this->assertStringContainsString('flock -n 9 || exit 0;', $launcher);
    }

    public function testInstalledDelugeVenvSelectionSurvivesTheUpdatePass(): void
    {
        // A Deluge store version is a virtualenv whose daemon lives in bin/ (ADR 0110), not at the top level.
        [$root, $home, $ops, $events] = $this->fixture('deluge-venv');
        $this->pmssEnsureDir($home.'/bin/deluge');
        $target = $root.'/store/deluge/2.2.0';
        $this->pmssWriteFile($target.'/bin/deluged', '#!/usr/bin/python3');
        $this->pmssWriteFile($target.'/bin/deluge-web', '#!/usr/bin/python3');
        $this->pmssWriteFile($root.'/projection.json', '{"versions":[{"client":"deluge","version":"2.2.0","kind":"venv"}]}');
        symlink($target, $home.'/bin/deluge/default');
        \pmssTorrentClientsPostSetupConverge('t', $home, $ops);
        $this->assertSame($target, readlink($home.'/bin/deluge/default'));
        $this->assertFalse(is_file($home.'/.config/pmss/torrentClients/deluge.notice'));
        $this->assertSame([], $events->logs);
    }

    public function testUnavailableSelectionUsesInstalledNonSystemDefault(): void
    {
        [$root, $home, $ops, $events] = $this->fixture('static-default');
        $catalog = json_decode((string) file_get_contents($root.'/catalog.json'), true);
        $catalog[0]['isDefault'] = false;
        $catalog[] = [
            'client' => 'qbittorrent', 'version' => '5.2.0', 'kind' => 'static',
            'url' => ['https://example.invalid/qb'], 'sha256' => str_repeat('a', 64),
            'compatFamily' => '5.2.0', 'cve' => [], 'disabled' => false, 'isDefault' => true,
        ];
        $this->pmssWriteFile($root.'/catalog.json', json_encode($catalog));
        $this->pmssWriteFile($root.'/projection.json', '{"versions":[{"client":"qbittorrent","version":"5.2.0","kind":"static"}]}');
        $target = $root.'/store/qbittorrent/5.2.0';
        $this->pmssWriteFile($target.'/qbittorrent-nox', 'binary');
        symlink($root.'/store/qbittorrent/old', $home.'/bin/qbittorrent/default');
        \pmssTorrentClientsPostSetupConverge('t', $home, $ops);
        $this->assertSame($target, readlink($home.'/bin/qbittorrent/default'));
        $this->assertSame(1, count($events->logs));
    }

    public function testRecreateRestoresBinAndEnvironmentCallsSharedPostSetup(): void
    {
        [$root, $home, $ops, $events] = $this->fixture('recreate');
        $backup = $root.'/backup';
        $this->pmssWriteFile($home.'/bin/support', 'fresh skeleton');
        $this->pmssWriteFile($backup.'/bin/qbittorrent/custom/qbittorrent-nox', 'user build');
        symlink('custom', $backup.'/bin/qbittorrent/default');
        chmod($home, 0700); chmod($backup, 0700);
        $uid = posix_geteuid(); $gid = posix_getegid();
        $left = \pmssRecreateRestoreHome($home, $backup, true, $uid, $gid, $uid, $uid);
        $this->assertSame('user build', file_get_contents($home.'/bin/qbittorrent/custom/qbittorrent-nox'));
        $this->assertSame('custom', readlink($home.'/bin/qbittorrent/default'));
        $this->assertSame('fresh skeleton', file_get_contents($backup.'/.pmss-recreate-bin-skel/support'));
        $this->assertTrue(in_array($backup.'/.pmss-recreate-bin-skel', $left, true));
        $this->assertStringContainsString('pmssUpdateUserEnvironment($userName',
            (string) file_get_contents(dirname(__DIR__, 3).'/recreateUser.php'));
        $this->assertStringContainsString('pmssTorrentClientsPostSetupConverge($user',
            (string) file_get_contents(dirname(__DIR__, 2).'/update/users.php'));
        \pmssTorrentClientsPostSetupConverge('t', $home, $ops);
        $this->assertSame([], $events->logs);
    }
}
