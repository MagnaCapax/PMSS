<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update.php';
require_once dirname(__DIR__, 2).'/update/users/filesystem.php';
require_once dirname(__DIR__, 2).'/update/users/webRoot.php';

/** Hermetic coverage for the customer-owned web-root migration contract. */
class UserWebRootMigrationTest extends TestCase
{
    private $homeRoot;
    private $home;
    private $user = 'webrootuser';

    protected function setUp(): void
    {
        $this->homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-web-root-');
        $this->home = $this->pmssEnsureUserWebHome($this->homeRoot, $this->user);
        $this->pmssEnsureDir($this->home.'/www/rutorrent');
    }

    public function testMovesPublicAndShareWithMetadataAndRelativeLinks(): void
    {
        $public = $this->home.'/www/public';
        $share = $this->home.'/www/rutorrent/share';
        $this->pmssWriteFile($public.'/index.html', 'public-payload');
        $this->pmssWriteFile($share.'/users/'.$this->user.'/settings.ini', 'share-payload');
        chmod($public, 0711);
        chmod($public.'/index.html', 0640);
        chmod($share, 0700);

        $publicStat = stat($public);
        $shareStat = stat($share);
        $messages = [];
        \pmssUserMigrateWebRootState($this->context(), $this->logger($messages));

        $this->assertTrue(is_link($this->home.'/www/public'));
        $this->assertSame('../.local/share/pmss/public', readlink($this->home.'/www/public'));
        $this->assertTrue(is_link($this->home.'/www/rutorrent/share'));
        $this->assertSame('../../.local/share/pmss/rutorrent/share', readlink($this->home.'/www/rutorrent/share'));
        $this->assertEquals('public-payload', file_get_contents($this->home.'/.local/share/pmss/public/index.html'));
        $this->assertEquals('share-payload', file_get_contents($this->home.'/.local/share/pmss/rutorrent/share/users/'.$this->user.'/settings.ini'));
        $this->assertSame($publicStat['mode'] & 07777, fileperms($this->home.'/.local/share/pmss/public') & 07777);
        $this->assertSame($shareStat['mode'] & 07777, fileperms($this->home.'/.local/share/pmss/rutorrent/share') & 07777);
        $this->assertTrue($this->pmssMessagesContain($messages, 'Migrated www/public'));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Migrated www/rutorrent/share'));
    }

    public function testExistingMigrationIsIdempotent(): void
    {
        $target = $this->home.'/.local/share/pmss/public';
        $this->pmssWriteFile($target.'/index.html', 'durable');
        chmod($target, 0710);
        $this->pmssCreateSymlinkOrSkip('../.local/share/pmss/public', $this->home.'/www/public');
        $beforeMode = fileperms($target) & 07777;

        $messages = [];
        \pmssUserMigrateWebRootState($this->context(), $this->logger($messages));

        $this->assertSame('../.local/share/pmss/public', readlink($this->home.'/www/public'));
        $this->assertSame('durable', file_get_contents($target.'/index.html'));
        $this->assertSame($beforeMode, fileperms($target) & 07777);
        $this->assertEquals([], $messages);
    }

    public function testDestinationConflictPreservesBothTrees(): void
    {
        $source = $this->home.'/www/public';
        $target = $this->home.'/.local/share/pmss/public';
        $this->pmssWriteFile($source.'/source.txt', 'source');
        $this->pmssWriteFile($target.'/destination.txt', 'destination');

        $messages = [];
        \pmssUserMigrateWebRootState($this->context(), $this->logger($messages));

        $this->assertTrue(is_dir($source) && !is_link($source));
        $this->assertTrue(is_dir($target) && !is_link($target));
        $this->assertEquals('source', file_get_contents($source.'/source.txt'));
        $this->assertEquals('destination', file_get_contents($target.'/destination.txt'));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Preserving destination conflict for www/public'));
    }

    public function testUnexpectedSourceSymlinkRemainsUntouched(): void
    {
        $outside = $this->homeRoot.'/outside-public';
        $this->pmssWriteFile($outside.'/index.html', 'outside');
        $this->pmssCreateSymlinkOrSkip($outside, $this->home.'/www/public');

        $messages = [];
        \pmssUserMigrateWebRootState($this->context(), $this->logger($messages));

        $this->assertTrue(is_link($this->home.'/www/public'));
        $this->assertSame($outside, readlink($this->home.'/www/public'));
        $this->assertEquals('outside', file_get_contents($outside.'/index.html'));
        $this->assertFalse(file_exists($this->home.'/.local/share/pmss/public'));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Refusing unexpected symlink at www/public'));
    }

    public function testExistingDurableTreeGetsLinkBackWhenSourceIsMissing(): void
    {
        $target = $this->home.'/.local/share/pmss/rutorrent/share';
        $this->pmssWriteFile($target.'/users/'.$this->user.'/settings.ini', 'durable-share');

        $messages = [];
        \pmssUserMigrateWebRootState($this->context(), $this->logger($messages));

        $this->assertTrue(is_link($this->home.'/www/rutorrent/share'));
        $this->assertSame('../../.local/share/pmss/rutorrent/share', readlink($this->home.'/www/rutorrent/share'));
        $this->assertEquals('durable-share', file_get_contents($target.'/users/'.$this->user.'/settings.ini'));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Restored symlink for www/rutorrent/share'));
    }

    private function context(): array
    {
        return ['user' => $this->user, 'home' => $this->home];
    }

    private function logger(array &$messages): callable
    {
        return static function (string $message) use (&$messages): void {
            $messages[] = $message;
        };
    }
}
