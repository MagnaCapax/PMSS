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

    /** The recipient is read from the root store only; ~/.notifyEmail is never read (ADR 0213). */
    public function testRecipientComesFromTheStoreNotTheHome(): void
    {
        $root = $this->pmssMakeTempDir('pmss-notification-email-');
        $this->pmssEnsureDir($root.'/alice');
        file_put_contents($root.'/alice/.notifyEmail', 'home@example.test');
        $this->pmssWithEnv(['PMSS_HOME_DIR' => $root], function () use ($root): void {
            $store = new \UserConfigStore($root.'/config');
            $base = ['ramMiB' => 512, 'rtorrentPort' => 5000, 'quota' => 5, 'quotaBurst' => 6];
            $this->assertSame(null, $store->readNotificationEmail('alice'));
            $this->assertTrue($store->set('alice', $base + ['notificationEmail' => 'store@example.test']));
            $this->assertSame('store@example.test', $store->readNotificationEmail('alice'));
            $this->assertTrue($store->set('alice', $base + ['notificationEmail' => "bad\naddress"]));
            $this->assertSame(null, $store->readNotificationEmail('alice'));
            $this->assertSame(null, $store->readNotificationEmail('../alice'));
        });
    }

    /** No provisioning CLI records a recipient on the node: the address is not a marker or a single-value key. */
    public function testNoProvisioningWriterForTheRecipient(): void
    {
        require_once dirname(__DIR__, 2).'/user/homeMarkerRegistry.php';
        $this->assertFalse(\pmssHomeMarkerIsKnown('.notifyEmail'));
        $this->assertFalse(in_array('notificationEmail', \UserConfigStore::VALUE_KEYS, true));
        $store = new \UserConfigStore($this->pmssMakeTempDir('pmss-notification-writer-').'/config');
        $this->assertTrue($store->set('alice', ['ramMiB' => 512, 'rtorrentPort' => 5000, 'quota' => 5, 'quotaBurst' => 6]));
        $this->assertFalse($store->setValue('alice', 'notificationEmail', 'store@example.test'));
        $this->assertSame(null, $store->readNotificationEmail('alice'));
    }
}
