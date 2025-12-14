<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/update/services/quota.php';

class QuotaFstabOptionsTest extends TestCase
{
    public function testNoChangeWhenQuotaOptionsPresent(): void
    {
        $dir = sys_get_temp_dir().'/pmss-quota-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $fstab = $dir.'/fstab';

        $original = "UUID=abc /home ext4 defaults,noatime,usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1 0 0\n";
        file_put_contents($fstab, $original);

        $messages = [];
        $logger = function (string $message) use (&$messages): void {
            $messages[] = $message;
        };

        \pmssEnsureQuotaOptions('/home', null, $logger, $fstab);

        $this->assertEquals($original, (string)file_get_contents($fstab));
        $this->assertTrue($this->messagesContain($messages, 'Quota options already present'), 'expected skip log');

        $this->cleanup($dir);
    }

    public function testAddsQuotaOptionsAndCreatesBackup(): void
    {
        $dir = sys_get_temp_dir().'/pmss-quota-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $fstab = $dir.'/fstab';

        $original = "UUID=abc /home ext4 defaults,noatime 0 0\n";
        file_put_contents($fstab, $original);

        $messages = [];
        $logger = function (string $message) use (&$messages): void {
            $messages[] = $message;
        };

        \pmssEnsureQuotaOptions('/home', null, $logger, $fstab);

        $updated = (string)file_get_contents($fstab);
        $this->assertStringContainsString('usrjquota=aquota.user', $updated);
        $this->assertStringContainsString('grpjquota=aquota.group', $updated);
        $this->assertStringContainsString('jqfmt=vfsv1', $updated);
        $this->assertStringContainsString('defaults,noatime', $updated);

        $backups = glob($fstab.'.pmss-backup-*') ?: [];
        $this->assertEquals(1, count($backups), 'expected exactly one backup');
        $this->assertEquals($original, (string)file_get_contents($backups[0]));
        $this->assertTrue($this->messagesContain($messages, 'Updated quota options'), 'expected update log');

        $this->cleanup($dir);
    }

    public function testDefaultsOnlyLineDropsDefaultsToken(): void
    {
        $dir = sys_get_temp_dir().'/pmss-quota-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $fstab = $dir.'/fstab';

        file_put_contents($fstab, "UUID=abc /home ext4 defaults 0 0\n");

        $messages = [];
        $logger = function (string $message) use (&$messages): void {
            $messages[] = $message;
        };

        \pmssEnsureQuotaOptions('/home', null, $logger, $fstab);

        $updated = (string)file_get_contents($fstab);
        $this->assertStringContainsString('usrjquota=aquota.user', $updated);
        $this->assertStringContainsString('grpjquota=aquota.group', $updated);
        $this->assertStringContainsString('jqfmt=vfsv1', $updated);
        $this->assertTrue(strpos($updated, 'defaults,') === false, 'expected defaults token removed');

        $this->cleanup($dir);
    }

    public function testMountPointMissingDoesNotTouchFile(): void
    {
        $dir = sys_get_temp_dir().'/pmss-quota-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $fstab = $dir.'/fstab';

        $original = "UUID=abc /srv ext4 defaults,noatime 0 0\n";
        file_put_contents($fstab, $original);

        $messages = [];
        $logger = function (string $message) use (&$messages): void {
            $messages[] = $message;
        };

        \pmssEnsureQuotaOptions('/home', null, $logger, $fstab);

        $this->assertEquals($original, (string)file_get_contents($fstab));
        $this->assertTrue($this->messagesContain($messages, 'not found'), 'expected not-found log');

        $this->cleanup($dir);
    }

    public function testUnreadableFstabSkipsConfiguration(): void
    {
        $dir = sys_get_temp_dir().'/pmss-quota-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $fstab = $dir.'/fstab';

        file_put_contents($fstab, "UUID=abc /home ext4 defaults 0 0\n");
        chmod($fstab, 0000);

        $messages = [];
        $logger = function (string $message) use (&$messages): void {
            $messages[] = $message;
        };

        \pmssEnsureQuotaOptions('/home', null, $logger, $fstab);

        $this->assertTrue($this->messagesContain($messages, 'not readable'), 'expected not-readable log');
        chmod($fstab, 0600);

        $this->cleanup($dir);
    }

    public function testWarnUnexpectedQuotaFilesNoUnexpectedEntries(): void
    {
        $dir = sys_get_temp_dir().'/pmss-quota-files-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        file_put_contents($dir.'/aquota.user', 'x');
        file_put_contents($dir.'/aquota.group', 'x');

        $messages = [];
        $logger = function (string $message) use (&$messages): void {
            $messages[] = $message;
        };

        \pmssWarnUnexpectedQuotaFiles($dir, $logger);
        $this->assertEquals([], $messages);

        $this->cleanup($dir);
    }

    public function testWarnUnexpectedQuotaFilesEscapesGarbageNames(): void
    {
        $dir = sys_get_temp_dir().'/pmss-quota-files-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);

        $garbage = 'aquota.gro'.chr(3);
        if (@file_put_contents($dir.'/'.$garbage, 'x') === false) {
            throw new SkipTest('filesystem does not support control character filenames');
        }

        $messages = [];
        $logger = function (string $message) use (&$messages): void {
            $messages[] = $message;
        };

        \pmssWarnUnexpectedQuotaFiles($dir, $logger);
        $this->assertTrue(count($messages) === 1, 'expected exactly one warning');
        $this->assertStringContainsString('aquota.gro\\003', $messages[0]);

        $this->cleanup($dir);
    }

    private function messagesContain(array $messages, string $needle): bool
    {
        foreach ($messages as $message) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function cleanup(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
