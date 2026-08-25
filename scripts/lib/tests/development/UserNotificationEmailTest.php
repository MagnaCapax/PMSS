<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/lib/user/userConfigStore.php';

class UserNotificationEmailTest extends TestCase
{
    /** @var string */
    private $home;

    protected function setUp(): void
    {
        parent::setUp();
        $root = $this->pmssMakeTempDir('pmss-notification-email-');
        $this->home = $root.'/alice';
        $this->pmssEnsureDir($this->home);
    }

    private function writeEmail(string $email): string
    {
        $path = $this->home.'/.notifyEmail';
        file_put_contents($path, $email);
        return $path;
    }

    public function testValidNotificationEmailsAreReadAndTrimmed(): void
    {
        foreach (["user@example.com\n", 'alerts+seedbox@example.co.uk'] as $email) {
            $this->assertSame(trim($email), \pmssUserNotificationEmailParse($email));
        }
    }

    public function testInvalidNotificationEmailsAreRejected(): void
    {
        $emails = [
            '',
            'not-an-email',
            'user @example.com',
            "user@example.com\nBcc: other@example.com",
            "user@example.com\0",
            str_repeat('a', 250).'@x.test',
        ];
        foreach ($emails as $email) {
            $this->assertSame(
                null,
                \pmssUserNotificationEmailParse($email),
                'expected invalid email: '.substr($email, 0, 40)
            );
        }
    }

    public function testMissingDirectoryAndFileAreRejected(): void
    {
        $this->assertSame(null, \pmssUserNotificationEmailRead($this->home.'/missing', 0));
        $this->assertSame(null, \pmssUserNotificationEmailRead($this->home, 0));
    }

    public function testSymlinkedNotificationEmailIsRejected(): void
    {
        $target = $this->home.'/target';
        file_put_contents($target, 'user@example.com');
        symlink($target, $this->home.'/.notifyEmail');

        $this->assertSame(null, \pmssUserNotificationEmailRead($this->home, (int) filegroup($target)));
    }

    public function testTrustedReadRejectsCustomerOwnedOrWritableFile(): void
    {
        $path = $this->writeEmail('user@example.com');
        chmod($path, 0660);
        clearstatcache(true, $path);
        $group = (int) filegroup($path);
        $this->assertSame(null, \pmssUserNotificationEmailRead($this->home, $group));

        chmod($path, 0640);
        clearstatcache(true, $path);
        $expected = @fileowner($path) === 0 ? 'user@example.com' : null;
        $this->assertSame($expected, \pmssUserNotificationEmailRead($this->home, $group));
        $this->assertSame(null, \pmssUserNotificationEmailRead($this->home, $group + 1));

        chmod($path, 0644);
        clearstatcache(true, $path);
        $this->assertSame(null, \pmssUserNotificationEmailRead($this->home, $group));
    }

    public function testStoreReaderRejectsInvalidOrUnknownUsername(): void
    {
        $root = dirname($this->home);
        $path = $this->writeEmail('user@example.com');
        chmod($path, 0660);
        clearstatcache(true, $path);
        $this->pmssWithEnv(['PMSS_HOME_DIR' => $root], function (): void {
            $store = new \UserConfigStore($this->home.'/config');
            $this->assertSame(null, $store->readNotificationEmail('../alice'));
            $this->assertSame(null, $store->readNotificationEmail('alice'));
        });
    }
}
