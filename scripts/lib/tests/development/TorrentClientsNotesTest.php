<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/TorrentClientsCardsRenderTrait.php';
require_once __DIR__.'/../../torrentClients/catalog.php';

final class TorrentClientsNotesTest extends TestCase
{
    use TorrentClientsCardsRenderTrait;

    public function testNotesAllowShortUnicodeTextAndRejectMalformedValues(): void
    {
        foreach (['x', str_repeat('a', 100), str_repeat('ä', 100), '<test> & "quoted"'] as $note)
            $this->assertTrue(\pmssTorrentClientsCatalogNoteValid($note));
        foreach (['', ' ', '    ', "\xc2\xa0", "\xe3\x80\x80", str_repeat('a', 101), str_repeat('ä', 101), null, [], 123, true,
            "line\nbreak", "tab\ttext", "nul\0text", "\x7f", "\xc2\x85", "\xe2\x80\xae", "\xff"] as $note)
            $this->assertFalse(\pmssTorrentClientsCatalogNoteValid($note));
    }

    public function testCatalogFieldIsOptionalButInvalidNoteRejectsTheWholeDocument(): void
    {
        $path = $this->pmssMakeTempPath('pmss-note-catalog-', '.json');
        $entry = ['client' => 'qbittorrent', 'version' => 'system', 'kind' => 'system',
            'binary' => '/usr/bin/qbittorrent-nox', 'url' => [], 'sha256' => '', 'compatFamily' => 'qbittorrent',
            'disabled' => false, 'isDefault' => true, 'cve' => []];
        foreach ([$entry, $entry + ['note' => 'Build note']] as $valid) {
            $this->pmssWriteFile($path, json_encode([$valid]));
            $this->assertSame([$valid], \pmssTorrentClientsCatalogLoad($path));
        }
        foreach (['', '  ', "\xc2\xa0", null, 42, "hidden\xe2\x80\x8btext"] as $note) {
            $this->pmssWriteFile($path, json_encode([$entry + ['note' => $note]]));
            $this->assertSame([], \pmssTorrentClientsCatalogLoad($path));
        }
    }

    public function testEditableCardsRenderEscapedLabelAndEveryOfferedNote(): void
    {
        $entries = $this->entries(['qbittorrent' => ['4.6.7-lt1.2', '5.2.4-lt2.0', 'system']]);
        $entries[0]['note'] = 'Certificate <checks> & "details"';
        $entries[1]['note'] = 'A second note';
        $this->withProjection($entries, function ($page): void {
            $card = $page()['qbittorrent-version'];
            $this->assertStringContainsAllStrings([
                '<p>4.6.7 (libtorrent 1.2): Certificate &lt;checks&gt; &amp; &quot;details&quot;</p>',
                '<p>5.2.4 (libtorrent 2.0): A second note</p>',
            ], $card);
            $this->assertTrue(strpos($card, '</select>') < strpos($card, '<p>4.6.7'));
            $this->assertStringNotContainsString('Certificate <checks>', $card);
            $this->assertStringNotContainsString('<p>system:', $card);
        });
    }

    public function testCombinedReadOnlyCardShowsOnlyTheRunningBuildsNote(): void
    {
        $entries = $this->entries(['qbittorrent' => ['system'], 'rtorrent' => ['system']]);
        $entries[0]['note'] = 'Current build';
        $this->withProjection($entries, function ($page): void {
            $card = $page()['torrent-client-versions'];
            $this->assertStringContainsString('<p>system: Current build</p>', $card);
            $this->assertSame(1, substr_count($card, 'Current build'));
        });
        $entries = $this->entries(['qbittorrent' => ['5.2.4-lt2.0']]);
        $entries[0]['note'] = 'Selected but not running';
        $this->withProjection($entries, function ($page, $home): void {
            $this->pmssEnsureDir($home.'/bin/qbittorrent');
            $this->pmssCreateSymlinkOrSkip('/home/.pmss/clients/qbittorrent/5.2.4-lt2.0', $home.'/bin/qbittorrent/default');
            $card = $page()['torrent-client-versions'];
            $this->assertStringNotContainsString('Selected but not running', $card);
        });
    }
}
