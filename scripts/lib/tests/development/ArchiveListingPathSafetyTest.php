<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Archive filename guards run before the recording listing boundary; no tar or download runs. */
final class ArchiveListingPathSafetyTest extends TestCase
{
    public function testMalformedFilenamesFailWithoutListingAnyArchive(): void
    {
        $paths = ['', "\0", "\0/fixture/archive", "/fixture/archive\0",
            "/fixture/archive\0suffix", "/fixture\0/archive"];
        $result = $this->fixture($paths);
        $this->assertSame(array_fill(0, count($paths), [false, [], false]), $result['results']);
        $this->assertSame([], $result['calls']);
    }

    public function testValidFilenameBytesAndListingCommandsArePreserved(): void
    {
        $paths = ['/fixture/archive.tar.gz', 'relative.tar.gz', '-archive.tar.gz',
            ' archive with spaces ', "/fixture/quote'archive", "/fixture/archive\nname"];
        $members = [['path' => 'dist/client/index.html', 'type' => '-']];
        $result = $this->fixture($paths, $members);
        $this->assertSame(array_fill(0, count($paths), [true, $members, true]), $result['results']);
        $expected = [];
        foreach ($paths as $path) {
            $arg = escapeshellarg($path);
            $expected[] = ['tar -tzf '.$arg.' 2>/dev/null', 'tar -tvzf '.$arg.' 2>/dev/null'];
            $expected[] = ['LC_ALL=C tar --quoting-style=escape -tzf '.$arg.' 2>/dev/null',
                'LC_ALL=C tar --quoting-style=escape -tvzf '.$arg.' 2>/dev/null'];
            $expected[] = ['tar -tzf '.$arg.' --quoting-style=literal 2>/dev/null',
                'tar -tvzf '.$arg.' --quoting-style=literal 2>/dev/null'];
        }
        $this->assertSame($expected, $result['calls']);
    }

    public function testUnavailableListingsRetainExistingFailureResults(): void
    {
        $result = $this->fixture(['/fixture/missing.tar.gz']);
        $this->assertSame([[false, [], false]], $result['results']);
        $this->assertSame(3, count($result['calls']));
    }

    /** Inline each consumer while replacing only its shared external listing dependency. */
    private function fixture(array $paths, array $members = []): array
    {
        $script = <<<'PHP'
namespace ArchiveListingPathFixture;
define('PMSS_TEST_MODE', true);
function pmssPinnedRemoteArchiveMembersRead($pathsCommand, $detailsCommand): array {
    $GLOBALS['calls'][] = [$pathsCommand, $detailsCommand];
    return $GLOBALS['members'];
}
PHP;
        $script .= '$GLOBALS["calls"] = []; $GLOBALS["members"] = '.var_export($members, true).';';
        foreach (['torrentClients/storeArchive.php', 'torrentClients/transmissionWebArchive.php',
            'update/apps/pikkumiesAgentboard.php'] as $library) {
            $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/'.$library, 'ArchiveListingPathFixture');
        }
        $script .= '$results = []; foreach ('.var_export($paths, true).' as $path) {'
            .'$results[] = [pmssTorrentClientsStoreArchiveIsSafe($path), '
            .'pmssTorrentClientsTransmissionWebArchiveList($path), pmssPikkumiesAgentboardArchiveMembersSafe($path)];'
            .'} echo json_encode(["results" => $results, "calls" => $GLOBALS["calls"]]);';
        return $this->pmssRunInlinePhpJson($script);
    }
}
