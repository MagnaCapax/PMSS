<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/torrentClients/store.php';

/** Root-side web convergence uses recording transport/tar callbacks, never the network. */
final class TorrentClientsTransmissionWebTest extends TestCase
{
    private function fixture(): array
    {
        $root = $this->pmssMakeTempDir('pmss-transmission-web-').'/store';
        $dir = $this->pmssEnsureDir($root.'/transmission/4.1.3');
        file_put_contents($dir.'/transmission-daemon', 'daemon');
        $entry = ['client' => 'transmission', 'version' => '4.1.3', 'kind' => 'static',
            'sha256' => hash('sha256', 'daemon'), 'web' => [
                'url' => ['https://example.invalid/web'], 'sha256' => hash('sha256', 'archive')]];
        return [$root, $dir, $entry];
    }

    private function ops(array &$events): array
    {
        return [
            'download' => static function (string $url, string $path) use (&$events): bool {
                $events[] = 'download';
                return file_put_contents($path, 'archive') === 7;
            },
            'list' => static function () use (&$events): array {
                $events[] = 'list';
                return [['path' => 'public_html/', 'type' => 'd'], ['path' => 'public_html/index.html', 'type' => '-']];
            },
            'extract' => static function (string $archive, string $stage) use (&$events): bool {
                $events[] = 'extract';
                mkdir($stage.'/public_html', 0700);
                return file_put_contents($stage.'/public_html/index.html', 'web') === 3;
            },
            'log' => static function (string $line) use (&$events): void { $events[] = $line; },
        ];
    }

    public function testExistingVerifiedBinaryReceivesWebThenSkipsCurrentPin(): void
    {
        [$root, $dir, $entry] = $this->fixture();
        $events = [];
        $webOps = $this->ops($events);
        $ops = ['root' => $root, 'web' => $webOps,
            'install' => static function (): bool { throw new \RuntimeException('Binary must be skipped'); }];
        $inode = fileinode($dir.'/transmission-daemon');
        $this->assertSame(['transmission/4.1.3' => 'installed'], \pmssTorrentClientsStoreConverge([$entry], $ops));
        $this->assertSame(['download', 'list', 'extract'], $events);
        $this->assertSame('web', file_get_contents($dir.'/public_html/index.html'));
        $this->assertSame($entry['web']['sha256']."\n", file_get_contents($dir.'/public_html.sha256'));
        foreach (['/public_html' => 0755, '/public_html/index.html' => 0644, '/public_html.sha256' => 0644] as $path => $mode) {
            $this->assertSame($mode, fileperms($dir.$path) & 0777);
            $this->assertSame(\pmssTorrentClientsStoreOwnerIds(), [fileowner($dir.$path), filegroup($dir.$path)]);
        }
        $events = [];
        $this->assertTrue(\pmssTorrentClientsTransmissionWebConverge($entry, $root, $webOps));
        $this->assertSame([], $events);
        $this->assertSame($inode, fileinode($dir.'/transmission-daemon'));
        $this->assertSame([], glob($dir.'/.transmission-web-*'));
    }

    public function testMismatchedPinReplacesOnlyTheWebTree(): void
    {
        [$root, $dir, $entry] = $this->fixture();
        mkdir($dir.'/public_html');
        file_put_contents($dir.'/public_html/index.html', 'old web');
        file_put_contents($dir.'/public_html/obsolete.js', 'old');
        file_put_contents($dir.'/public_html.sha256', str_repeat('0', 64)."\n");
        $events = [];
        $this->assertTrue(\pmssTorrentClientsTransmissionWebConverge($entry, $root, $this->ops($events)));
        $this->assertSame('web', file_get_contents($dir.'/public_html/index.html'));
        $this->assertFalse(file_exists($dir.'/public_html/obsolete.js'));
        $this->assertSame('daemon', file_get_contents($dir.'/transmission-daemon'));
        $this->assertSame([], glob($dir.'/.transmission-web-*'));
    }

    public function testNewBinaryAlsoReceivesWebAfterInstallation(): void
    {
        [$root, $dir, $entry] = $this->fixture();
        unlink($dir.'/transmission-daemon');
        $events = [];
        $this->assertSame(['transmission/4.1.3' => 'installed'], \pmssTorrentClientsStoreConverge([$entry], [
            'root' => $root, 'web' => $this->ops($events),
            'free' => static function (): int { return 10 * 1024 * 1024 * 1024; },
            'size' => static function (): int { return 0; },
            'install' => static function ($entry, $binary): bool { return file_put_contents($binary, 'daemon') === 6; },
        ]));
        $this->assertSame(['download', 'list', 'extract'], $events);
        $this->assertSame('web', file_get_contents($dir.'/public_html/index.html'));
    }

    public function testRejectedWebKeepsOldWebAndBinaryAndLogsOnce(): void
    {
        foreach (['traversal', 'symlink', 'hardlink', 'checksum', 'download', 'extract', 'exception'] as $case) {
            [$root, $dir, $entry] = $this->fixture();
            mkdir($dir.'/public_html');
            file_put_contents($dir.'/public_html/index.html', 'old web');
            $events = [];
            $ops = $this->ops($events);
            if ($case === 'traversal') $ops['list'] = static function (): array { return [['path' => 'public_html/../x', 'type' => '-']]; };
            if ($case === 'checksum') $entry['web']['sha256'] = str_repeat('0', 64);
            if ($case === 'download') $ops['download'] = static function (): bool { return false; };
            if ($case === 'extract') $ops['extract'] = static function (): bool { return false; };
            if ($case === 'exception') $ops['list'] = static function (): array { throw new \RuntimeException('private error'); };
            if (in_array($case, ['symlink', 'hardlink'], true)) {
                $extract = $ops['extract'];
                $ops['extract'] = static function ($archive, $stage) use ($extract, $case): bool {
                    $extract($archive, $stage);
                    $path = $stage.'/public_html/linked';
                    return $case === 'symlink' ? symlink('index.html', $path) : link($stage.'/public_html/index.html', $path);
                };
            }
            $inode = fileinode($dir.'/transmission-daemon');
            $this->assertSame(['transmission/4.1.3' => 'installed'], \pmssTorrentClientsStoreConverge([$entry], ['root' => $root, 'web' => $ops]));
            $warnings = array_values(array_filter($events, static function ($event): bool { return strpos($event, '[WARN]') === 0; }));
            $this->assertSame(1, count($warnings), $case);
            $this->assertSame('old web', file_get_contents($dir.'/public_html/index.html'), $case);
            $this->assertSame('daemon', file_get_contents($dir.'/transmission-daemon'), $case);
            $this->assertSame($inode, fileinode($dir.'/transmission-daemon'), $case);
            $this->assertFalse(file_exists($dir.'/public_html.sha256'));
            if (in_array($case, ['traversal', 'checksum', 'download', 'exception'], true)) $this->assertFalse(in_array('extract', $events, true));
            $this->assertSame([], glob($dir.'/.transmission-web-*'));
        }
    }

    public function testMemberValidationRejectsEscapesAndAllSpecialTypes(): void
    {
        foreach (['public_html/', 'public_html/images/', 'public_html/app-1.0_min.js'] as $path) {
            $this->assertTrue(\pmssTorrentClientsTransmissionWebArchiveSafe([['path' => $path, 'type' => substr($path, -1) === '/' ? 'd' : '-']]));
        }
        foreach (['../x', 'public_html/../x', '/public_html/x', './public_html/x', 'public_html/./x',
            'public_html//x', 'other/x', 'public_html/a b', "public_html/x\n", 'public_html/x\\y'] as $path) {
            $this->assertFalse(\pmssTorrentClientsTransmissionWebArchiveSafe([['path' => $path, 'type' => '-']]));
        }
        foreach (['l', 'h', 'b', 'c', 'p', 's'] as $type) {
            $this->assertFalse(\pmssTorrentClientsTransmissionWebArchiveSafe([['path' => 'public_html/x', 'type' => $type]]));
        }
        $this->assertFalse(\pmssTorrentClientsTransmissionWebArchiveSafe([['path' => 'public_html', 'type' => 'd']]));
        $this->assertFalse(\pmssTorrentClientsTransmissionWebArchiveSafe([]));
    }

    public function testUnsafeTargetsAndUnverifiedBinaryPreventDownloads(): void
    {
        foreach (['directory-link', 'marker-link', 'writable-version', 'binary-pin'] as $case) {
            [$root, $dir, $entry] = $this->fixture();
            if ($case === 'directory-link') symlink($dir, $dir.'/public_html');
            if ($case === 'marker-link') symlink($dir.'/transmission-daemon', $dir.'/public_html.sha256');
            if ($case === 'writable-version') chmod($dir, 0777);
            if ($case === 'binary-pin') $entry['sha256'] = str_repeat('0', 64);
            $events = [];
            $this->assertFalse(\pmssTorrentClientsTransmissionWebConverge($entry, $root, $this->ops($events)));
            $this->assertSame(1, count($events));
            $this->assertSame('daemon', file_get_contents($dir.'/transmission-daemon'));
            $this->assertSame([], glob($dir.'/.transmission-web-*'));
            chmod($dir, 0755);
        }
    }
}
