<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TemporaryHomeRootTestCase.php';
require_once __DIR__.'/../../user/qbittorrentStoppedConfig.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/qbittorrentStoppedConfig.php';

/** Refusal notices replace stale state and distinguish process ownership. */
final class QbittorrentStoppedNoticeTest extends TemporaryHomeRootTestCase
{
    public function testUnmanagedSameUidProcessHasItsOwnNoticeInsteadOfLockTimeout(): void
    {
        $message = '';
        $this->assertFalse(\pmssQbittorrentPasswordPrepare('t', '/fixture/t', [
            'stop' => static function (): void {}, 'pause' => static function (): void {},
            'accountPids' => static function (): array { return [101]; },
            'restore' => static function (): void { throw new \RuntimeException('Running config restored'); },
            'notice' => static function ($home, $text) use (&$message): void { $message = $text; },
        ]));
        $this->assertStringContainsString('another qbittorrent-nox process of this account is running (not started by PMSS)', $message);
        $this->assertStringNotContainsString('lock timeout', $message);
    }

    public function testConfigLinkRefusalReplacesAStaleNoticeOnBothSides(): void
    {
        $home = $this->pmssMakeTempDir('pmss-qbt-link-notice-');
        $original = $this->pmssWriteFile($home.'/private.conf', 'untouched');
        $this->pmssEnsureDir($home.'/.config/qBittorrent');
        $this->pmssCreateSymlinkOrSkip($original, $home.'/.config/qBittorrent/qBittorrent.conf');
        foreach (['pmssQbittorrentRestore', 'pmssQbittorrentFrontendRestore'] as $restore) {
            $this->pmssWriteFile($home.'/.config/pmss/torrentClients/qbittorrent.notice', 'stale busy message');
            $this->assertFalse($restore($home, ['stopped' => static function (): bool { return true; }]));
            $this->assertSame("qBittorrent.conf is a link; PMSS will not write through it\n",
                file_get_contents($home.'/.config/pmss/torrentClients/qbittorrent.notice'));
            $this->assertSame('untouched', file_get_contents($original));
        }
    }
}
