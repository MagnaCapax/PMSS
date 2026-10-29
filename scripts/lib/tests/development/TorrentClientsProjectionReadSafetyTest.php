<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Exercise projection read boundaries without accessing the installed catalog. */
final class TorrentClientsProjectionReadSafetyTest extends TestCase
{
    private function fixture(string $body): array
    {
        $script = <<<'PHP'
namespace ProjectionReadSafetyFixture;
function is_file($path) { $GLOBALS['events'][] = ['file', $path]; return $GLOBALS['file'] ?? true; }
function is_link($path) { return $GLOBALS['link'] ?? false; }
function is_readable($path) { return $GLOBALS['readable'] ?? true; }
function filesize($path) { return $GLOBALS['size'] ?? false; }
function file_get_contents($path, $include = false, $context = null, $offset = 0, $length = null) {
    $GLOBALS['events'][] = ['read', $path, $include, $context, $offset, $length];
    $raw = $GLOBALS['raw'];
    return is_string($raw) && $length !== null ? substr($raw, $offset, $length) : $raw;
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace(
            'etc/skel/www/configureTorrentClientsVersion.php', 'ProjectionReadSafetyFixture');
        return $this->pmssRunInlinePhpJson($script.$body);
    }

    public function testInvalidPathsReturnUnavailableWithoutFilesystemCalls(): void
    {
        $actual = $this->fixture(<<<'PHP'
$results = [];
foreach (['', null, false, 123, [], new \stdClass(), "\0/catalog", "/cat\0alog", "/catalog\0"] as $path) {
    $GLOBALS['events'] = [];
    $results[] = [pmssConfigureTorrentClientProjectionRead($path),
        pmssConfigureTorrentClientVersionsAvailable($path), pmssConfigureTorrentClientVersionsRead($path),
        pmssConfigureTorrentClientNotInstalledRead($path), $GLOBALS['events']];
}
echo json_encode($results);
PHP
        );
        $zero = ['qbittorrent' => 0, 'rtorrent' => 0, 'deluge' => 0, 'transmission' => 0];
        $this->assertSame(array_fill(0, 9, [null, false, [], $zero, []]), $actual);
    }

    public function testReadCapSurvivesUnavailableOrStaleSizeObservations(): void
    {
        $actual = $this->fixture(<<<'PHP'
$results = [];
$entry = ['client' => 'qbittorrent', 'version' => 'system', 'kind' => 'system'];
$base = json_encode(['versions' => [$entry], 'notInstalled' => ['qbittorrent' => 2]]);
foreach ([false, 0, 65536, 65537] as $size) {
    foreach ([65535, 65536, 65537, 131072] as $bytes) {
        foreach (['pmssConfigureTorrentClientProjectionRead', 'pmssConfigureTorrentClientVersionsAvailable',
            'pmssConfigureTorrentClientVersionsRead', 'pmssConfigureTorrentClientNotInstalledRead'] as $reader) {
            $GLOBALS['events'] = [];
            $GLOBALS['size'] = $size;
            // Whitespace padding keeps the JSON valid on both sides of the byte boundary.
            $GLOBALS['raw'] = $base.str_repeat(' ', $bytes - strlen($base));
            $read = __NAMESPACE__.'\\'.$reader;
            $results[] = [$size, $bytes, $reader, $read('/fixture/catalog '), $GLOBALS['events']];
        }
    }
}
echo json_encode($results);
PHP
        );
        $entry = ['client' => 'qbittorrent', 'version' => 'system', 'kind' => 'system'];
        $zero = ['qbittorrent' => 0, 'rtorrent' => 0, 'deluge' => 0, 'transmission' => 0];
        foreach ($actual as [$size, $bytes, $reader, $result, $events]) {
            $ok = $size !== 65537 && $bytes <= 65536;
            $expected = [
                'pmssConfigureTorrentClientProjectionRead' => $ok
                    ? ['versions' => [$entry], 'notInstalled' => ['qbittorrent' => 2]] : null,
                'pmssConfigureTorrentClientVersionsAvailable' => $ok,
                'pmssConfigureTorrentClientVersionsRead' => $ok ? ['system' => $entry] : [],
                'pmssConfigureTorrentClientNotInstalledRead' => $ok ? array_replace($zero, ['qbittorrent' => 2]) : $zero,
            ];
            $this->assertSame($expected[$reader], $result, $reader);
            $expectedEvents = [['file', '/fixture/catalog ']];
            if ($size !== 65537) $expectedEvents[] = ['read', '/fixture/catalog ', false, null, 0, 65537];
            $this->assertSame($expectedEvents, $events, $reader);
        }
    }

    public function testFailedReadsAndMalformedSchemasKeepEmptyVersionResults(): void
    {
        $actual = $this->fixture(<<<'PHP'
$results = [];
foreach ([false, '', '{', 'null', 'false', '1', '"text"', '{}', '{"versions":null}',
    '{"versions":false}', '{"versions":"invalid"}', '{"versions":[]}'] as $raw) {
    $GLOBALS['raw'] = $raw;
    $results[] = pmssConfigureTorrentClientVersionsRead('/fixture/catalog');
}
echo json_encode($results);
PHP
        );
        $this->assertSame(array_fill(0, 12, []), $actual);
    }

    public function testAcceptedVersionsRetainFilteringOrderAndDuplicatePrecedence(): void
    {
        $actual = $this->fixture(<<<'PHP'
$first = ['client' => 'qbittorrent', 'version' => 'first', 'kind' => 'system', 'note' => 'old'];
$last = array_replace($first, ['note' => 'new']);
$second = array_replace($first, ['version' => 'second', 'kind' => 'static']);
$deluge = ['client' => 'deluge', 'version' => '2.1.1', 'kind' => 'venv'];
$GLOBALS['raw'] = json_encode(['versions' => [$first, null, 'invalid', $deluge,
    array_replace($first, ['version' => '../bad']), array_replace($first, ['kind' => 'unknown']),
    array_replace($first, ['version' => 1]), $second, $last]]);
echo json_encode([pmssConfigureTorrentClientVersionsRead('/fixture/catalog'),
    pmssConfigureTorrentClientVersionsRead('/fixture/catalog', 'deluge')]);
PHP
        );
        $first = ['client' => 'qbittorrent', 'version' => 'first', 'kind' => 'system', 'note' => 'new'];
        $second = array_replace($first, ['version' => 'second', 'kind' => 'static', 'note' => 'old']);
        $this->assertSame([['first' => $first, 'second' => $second],
            ['2.1.1' => ['client' => 'deluge', 'version' => '2.1.1', 'kind' => 'venv']]], $actual);
    }
}
