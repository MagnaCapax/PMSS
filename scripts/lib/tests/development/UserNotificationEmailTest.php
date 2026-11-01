<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/lib/user/userConfigStore.php';

class UserNotificationEmailTest extends TestCase
{
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

    /** The recipient comes from the root store only; ~/.notifyEmail is a display copy (ADR 0213). */
    public function testRecipientComesFromTheStoreNotTheHome(): void
    {
        $root = $this->pmssMakeTempDir('pmss-notification-email-');
        $this->pmssEnsureDir($root.'/alice');
        file_put_contents($root.'/alice/.notifyEmail', 'home@example.test');
        $this->pmssWithEnv(['PMSS_HOME_DIR' => $root], function () use ($root): void {
            $store = new \UserConfigStore($root.'/config');
            $this->assertSame(null, $store->readNotificationEmail('alice'));
            $this->assertTrue($store->set('alice', ['ramMiB' => 512, 'rtorrentPort' => 5000, 'quota' => 5, 'quotaBurst' => 6]));
            $this->assertTrue($store->setValue('alice', 'notificationEmail', 'store@example.test'));
            $this->assertSame('store@example.test', $store->readNotificationEmail('alice'));
            $this->assertTrue($store->setValue('alice', 'notificationEmail', "bad\naddress"));
            $this->assertSame(null, $store->readNotificationEmail('alice'));
            $this->assertSame(null, $store->readNotificationEmail('../alice'));
        });
    }
}
