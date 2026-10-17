<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/qbittorrent.php';

/** Keep the managed disk I/O key compatible with the selected family. */
final class TorrentClientsP1ConfigTest extends TestCase
{
    public function testDiskIoTypeFamilyBoundaries(): void
    {
        $this->assertSame('Posix', \pmssQbittorrentDiskIoType(''));
        $this->assertSame('Posix', \pmssQbittorrentDiskIoType('4.6.7'));
        $this->assertSame('Posix', \pmssQbittorrentDiskIoType('5.0.0'));
        $this->assertSame('SimplePreadPwrite', \pmssQbittorrentDiskIoType('5.0.1'));
        $this->assertSame('SimplePreadPwrite', \pmssQbittorrentDiskIoType('5.1.0'));
        $this->assertSame(null, \pmssQbittorrentDiskIoType('lt1.2'));
        $this->assertSame("[BitTorrent]\nOther=1\n", \pmssQbittorrentDiskIoTypeRemove(
            "[BitTorrent]\nSession\\DiskIOType=Posix\nOther=1\n"));
    }

    public function testManagedEntriesOmitUnsupportedKeyAndRetainWebUiSafety(): void
    {
        $find = static function (array $entries, string $key): array {
            return array_values(array_filter($entries, static function (array $entry) use ($key): bool {
                return $entry['key'] === $key;
            }));
        };
        $key = 'Session\\DiskIOType';
        $this->assertSame(array(), $find(\pmssQbittorrentManagedConfigEntries('lt1.2'), $key));
        $this->assertSame('Posix', $find(\pmssQbittorrentManagedConfigEntries('4.6.7'), $key)[0]['value']);
        $this->assertSame('SimplePreadPwrite', $find(\pmssQbittorrentManagedConfigEntries('5.0.1'), $key)[0]['value']);
        $this->assertSame('true', $find(\pmssQbittorrentManagedConfigEntries('5.0.1'), 'WebUI\\CSRFProtection')[0]['value']);
    }
}
